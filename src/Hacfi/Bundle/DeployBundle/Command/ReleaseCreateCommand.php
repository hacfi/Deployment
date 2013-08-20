<?php

namespace Hacfi\Bundle\DeployBundle\Command;


use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class ReleaseCreateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('deploy:release:create')
            ->setDescription('Create a release')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'Which repository do you want to deploy?')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Branch', 'master')
            ->addOption('create-tag', null, InputOption::VALUE_NONE, 'Create a tag for the release');
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');

        if ($input->isInteractive()) {
            if (!$dialog->askConfirmation($output, sprintf('<info>%s</info> [<comment>%s</comment>]%s ', 'Do you confirm generation', 'yes', '?'), true)) {
                $output->writeln('<error>Command aborted</error>');

                return 1;
            }
        }

        $output->writeln('');

        $repository = $this->validateRepository($input->getOption('repository'));

        if (!$repository) {
            $output->writeln('<error>You must insert a package name</error>');

            return 1;
        }

        $output->writeln(sprintf('<info>Creating release for repository "%s"...</info>', $repository));


        $dataDir = realpath($this->getContainer()->get('kernel')->getRootDir().'/..').'/data/';
        $vendorDir = $dataDir.'vendor/';
        $repoDir = $dataDir.$repository.'/';

        $gitDir = $repoDir.'git/';

        /** @var \Symfony\Component\Filesystem\Filesystem $filesystem */
        $filesystem = $this->getContainer()->get('filesystem');

        $filesystem->mkdir($vendorDir);

        $releasesDir = $repoDir.'releases/';

        $releaseNumber = 0;
        do {
            $releaseNumber++;
            $releaseName = sprintf('release-%04d', $releaseNumber);
            $releaseDir  = $releasesDir.$releaseName.'/';
        } while ($filesystem->exists($releaseDir));

        $filesystem->mkdir($releaseDir);

        if (!$filesystem->exists($gitDir)) {
            // clone here
            $output->writeln('<error>Repository not cloned yet..aborting</error>');

            $source = 'git@github.com:hacfi/---.git';

            $gitProcess = new Process(sprintf('git clone --bare %s %s', $source, $gitDir));
            $gitProcess->setTimeout(900);

            $gitProcess->run(
                function ($type, $buffer) use ($output) {
                    if (Process::ERR === $type) {
                        $output->writeln(sprintf('<bg=red>%s</>', $buffer));
                    } else {
                        $output->writeln(sprintf('%s', $buffer));
                    }
                }
            );

//            return 1;
        }

        $gitProcess = new Process('git fetch --all', $gitDir);
        $gitProcess->setTimeout(900);

        $gitProcess->run(
            function ($type, $buffer) use ($output) {
                $output->writeln(sprintf('%s', $buffer));
            }
        );
//        $gitProcess->wait(
//            function ($type, $buffer) use ($output) {
//                if (Process::ERR === $type) {
//                    $output->writeln(sprintf('<bg=red>%s</>', $buffer));
//                } else {
//                    $output->writeln(sprintf('%s', $buffer));
//                }
//            }
//        );


        // update

        // @TODO: Validate
        $branch = $input->getOption('branch');

        $gitProcess = new Process(sprintf('git archive --remote=%s --format=tar %s | tar -xf -', $gitDir, $branch), $releaseDir);
        $gitProcess->setTimeout(900);

//        $gitProcess->start();
// ... do other things then use wait instead

        $gitProcess->run(
            function ($type, $buffer) use ($output) {
                if (Process::ERR === $type) {
                    $output->writeln(sprintf('<bg=red>%s</>', $buffer));
                } else {
                    $output->writeln(sprintf('%s', $buffer));
                }
            }
        );

        $output->writeln(sprintf('<info>Created release dir in dir %s</info>', $releaseDir));

        if ($filesystem->exists($repoDir.'default/parameters.yml')) {
            $filesystem->copy($repoDir.'default/parameters.yml', $releaseDir.'app/config', true);
        }

        $composerDownloadProcess = new Process(sprintf('curl -sS https://getcomposer.org/installer | php'), $releaseDir);
        $composerDownloadProcess->setTimeout(120);
        $composerDownloadProcess->run(
            function ($type, $buffer) use ($output) {
                if (Process::ERR === $type) {
                    $output->writeln(sprintf('<bg=red>%s</>', trim($buffer)));
                } else {
                    $output->writeln(sprintf('%s', trim($buffer)));
                }
            }
        );

        $composerHash = sha1_file($releaseDir.'composer.lock');

        if (!$composerHash) {
            $output->writeln(sprintf('<error>Composer.lock not found</error>'));
        }

        $output->writeln(sprintf('<info>Composer.lock hash: %s</info>', $composerHash));


        $freshVendor = true;
        if ($filesystem->exists($vendorDir.$composerHash)) {
            $filesystem->symlink($vendorDir.$composerHash, $releaseDir.'vendor');
            $freshVendor = false;
        }

        $composerInstallProcess = new Process(sprintf('php composer.phar install --prefer-dist --optimize-autoloader'), $releaseDir);
        $composerInstallProcess->setTimeout(900);
        $composerInstallProcess->run(
            function ($type, $buffer) use ($output) {
                if (Process::ERR === $type) {
                    $output->writeln(sprintf('<bg=red>%s</>', trim($buffer)));
                } else {
                    $output->writeln(sprintf('%s', trim($buffer)));
                }
            }
        );

        $filesystem->remove($releaseDir.'composer.phar');

        if ($freshVendor) {
            $filesystem->rename($releaseDir.'vendor', $vendorDir.$composerHash);
            $filesystem->symlink($vendorDir.$composerHash, $releaseDir.'vendor');
        }

        $filesystem->remove($releaseDir.'app/cache');

        $filesystem->mkdir($releaseDir.'app/cache');
        $filesystem->mkdir($releaseDir.'app/logs');

        $tarProcess = new Process(sprintf('tar chzvf %s%s.tar.gz .', $releasesDir, $releaseName), $releaseDir);
        $tarProcess->setTimeout(900);
        $tarProcess->run();

        $output->writeln('');
        $output->writeln(sprintf('<info>Release created: %s%s.tar.gz </info>', $releasesDir, $releaseName));

        if ($input->getOption('create-tag')) {
            // add tag
        }


        return 0;
    }


    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $output->writeln(
            array(
                '',
                $this->getHelperSet()->get('formatter')->formatBlock('Deploy', 'bg=blue;fg=white', true),
                '',
            )
        );

        $repositories = array('kis', 'gr');
        $output->writeln('<info>Available:</info>');
        $output->writeln(array_values($repositories));
        $output->writeln('');

        while (true) {
            $repository = $dialog->askAndValidate(
                $output,
                sprintf('<info>%s</info> [<comment>%s</comment>]%s ', 'Which repo?', $input->getOption('repository'), ':'),
                array($this, 'validateRepository'),
                false,
                $input->getOption('repository'),
                $repositories
            );


            // check / trigger retry
            if (false) { //$this->getGenerator()->isReservedKeyword($repository)
                $output->writeln(sprintf('<bg=red> "%s" is a reserved word</>.', $repository));
                continue;
            }

            try {
//                $b = $this->getContainer()->get('kernel')->getBundle($bundle);

                if (true) { //!file_exists($b->getPath().'/Entity/'.str_replace('\\', '/', $repository).'.php')
                    break;
                }

                $output->writeln(sprintf('<bg=red>Entity "%s" already exists</>.', $repository));
            } catch (\Exception $e) {
                $output->writeln(sprintf('<bg=red>Bundle "%s" does not exist.</>', $repository));
            }
        }
        $input->setOption('repository', $repository);
        //$package = $dialog->ask($output, '<question>Please enter the name of the package you want to install</question> ');
    }


    public function validateRepository($value)
    {
        $b = $this->getContainer()->get('kernel');

        if (false) { // @TODO: Validate
            throw new \InvalidArgumentException(sprintf('Repository "%s" was not found', $value));
        }

        return $value;
    }
}
