<?php

declare(strict_types=1);

namespace Composer\Satis\Console\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Util\RemoteFilesystem;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScanOrganisationCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('scan-organisation')
            ->setDefinition([
                new InputArgument('organisation', InputArgument::REQUIRED, 'GitHub organization'),
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json'),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>scan-organisation</info> command scans the given organisation and adds all
                repositories to the json file (satis.json is used by default). You will need to run
                <comment>build</comment> command to fetch updates from repository.
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $gitHubOrganization = $input->getArgument('organisation');
        $configFile = $input->getArgument('file');

        if (preg_match('{^https?://}i', $configFile)) {
            $output->writeln('<error>Unable to write to remote file ' . $configFile . '</error>');

            return 2;
        }

        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: ' . $configFile . '</error>');

            return 1;
        }

        $config = $file->read();
        if (!isset($config['repositories']) || !is_array($config['repositories'])) {
            $config['repositories'] = [];
        }

        $application = $this->getApplication();
        $composer = $application->getComposer(true, $config);

        $rfs = new RemoteFilesystem(
            $this->getIO(),
            $composer->getConfig()
        );

        $repositories = $this->loadGithubOrganisation($rfs, $output, $gitHubOrganization);
        $repositoryUrls = array_map(function($repository) {
            return $repository['url'];
        }, $config['repositories']);

        foreach ($repositories as $repository) {
            if (!in_array($repository['url'], $repositoryUrls)) {
                $config['repositories'][] = $repository;
            }
        }

        $file->write($config);

        $output->writeln([
            '',
            $formatter->formatBlock('Your configuration file successfully updated! It\'s time to rebuild your repository', 'bg=blue;fg=white', true),
            '',
        ]);

        return 0;
    }

    private function loadGithubOrganisation(
        RemoteFilesystem $rfs,
        OutputInterface $output,
        string $organisation,
        string $after = 'null'
    ): array {
        $graphqlBody = <<<GRAPHQL
        {
            organization(login: "$organisation") {
                repositories(first: 100, after: $after) {
                    totalCount
                    nodes {
                        id
                        sshUrl
                        folder: object(expression: "HEAD:composer.json") {
                            ... on Blob {
                                text
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
        }
        GRAPHQL;

        $postData = ['query' => $graphqlBody];

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/json'],
                'content' => json_encode($postData, JSON_THROW_ON_ERROR),
            ],
        ];

        $json = $rfs->getContents('github.com', 'https://api.github.com/graphql', true, $opts);
        $response = json_decode($json, true, JSON_THROW_ON_ERROR);

        if (isset($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $output->writeln(
                    '<error>' .
                    $error['message'] ?? 'Unknown error Github GraphQL' .
                    '</error>'
                );
            }
        }

        $repositories = [];

        foreach ($response['data']['organization']['repositories']['nodes'] ?? [] as $repository) {
            if (null !== $repository['folder']) {
                $composerJson = json_decode($repository['folder']['text'] ?? '{}', true, JSON_THROW_ON_ERROR);

                if (false === isset($composerJson['name'])) {
                    continue;
                }

                $repositories[] = [
                    'type' => 'vcs',
                    'url' => $repository['sshUrl'],
                ];
            }
        }

        if (isset($response['data']['organization']['repositories']['pageInfo']['hasNextPage'])) {
            if (true === $response['data']['organization']['repositories']['pageInfo']['hasNextPage']) {
                $nextPageResults = $this->loadGithubOrganisation(
                    $rfs,
                    $output,
                    $organisation,
                    '"' . $response['data']['organization']['repositories']['pageInfo']['endCursor'] . '"'
                );

                $repositories = array_merge($repositories, $nextPageResults);
            }
        }

        return $repositories;
    }
}
