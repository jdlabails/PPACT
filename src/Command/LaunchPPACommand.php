<?php

namespace App\Command;

use App\Entity\Analyze;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class LaunchPPACommand extends Command
{
    use LockableTrait;

    private $params;
    private $workspaceDir;
    private $entityManager;

    public function __construct($ppactParams, $projectDir, EntityManagerInterface $entityManager)
    {
        $this->params = $ppactParams;
        $this->workspaceDir = $projectDir.'/workspace';
        $this->workspaceDir = '/home/jd/Projets';
        $this->entityManager = $entityManager;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('control-tower:launch-projects')
            ->setDescription('Launch analysis of projects configrured.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return 0;
        }

        //dump($this->params);die;
        $output->writeln([
            'Control Tower for PPA ready',
            '===========================',
            '',
        ]);

        $launchProcesses = [];
        $readProcesses = [];
        foreach ($this->params as $project => $params) {
            $dirPath = $this->workspaceDir.'/'.$project;

            $cmdLine = 'cd '.$dirPath . ' && git pull';
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0755);
                $cmdLine = 'git clone '.$params['url'].' '.$dirPath;
                $cmdLine.=' && cd '.$dirPath;
            }

            //todo detect sf 2 ou 3 for console path
            $cmdLine.=' && composer install && php bin/console ppa:analyse:launch';

            $process = new Process($cmdLine);
            $process->start();

            $launchProcesses[$project]=$process;
            $readProcesses[$project]=new Process('cd '.$dirPath . ' && php bin/console ppa:analyse:read');

            $output->writeln('Workspace setup and analysis of '.$project.' have begun');
        }

        $output->writeln([
            '',
            'Waiting for status...',
            ''
        ]);

        do {
            $aProcessIsStillRunning = false;
            foreach ($launchProcesses as $project => $process) {
                if ($process->isRunning()) {
                    $aProcessIsStillRunning = true;
                    continue;
                }

                // end of anaylisis, write it and start reading
                $output->writeln([
                    '<info>'.$project.' is built, waiting for the results...</info>',
                    '',
                ]);
                $output->writeln([
                    '<info>Status for '.$project.'</info>',
                    $process->getOutput(),
                    ''
                ],
                    OutputInterface::VERBOSITY_VERBOSE);
                unset($launchProcesses[$project]);
                $readProcesses[$project]->start();
            }

            foreach ($readProcesses as $project => $process) {
                if (!$process->isStarted() || $process->isRunning()) {
                    $aProcessIsStillRunning = true;
                    continue;
                }

                $json = $process->getOutput();
                $output->writeln([
                    '<info>result of analysis for '.$project.'</info>',
                    $json,
                    ''
                ],
                    OutputInterface::VERBOSITY_VERBOSE);

                if (trim($json) == 'AIP') {
                    sleep(3);
                    $process->start();
                    $aProcessIsStillRunning = true;
                    continue;
                }

                $analysis = new Analyze();
                $analysis->setProjectName($project);
                $analysisAsArray = json_decode($json, true);
                if (null === $analysisAsArray) {
                    unset($readProcesses[$project]);
                    $output->writeln('<error>Analysis finished but not readable for : '.$project.'</error>');
                    continue;
                }
                $analysis->setFromArray($analysisAsArray);

                $this->entityManager->persist($analysis);
                $this->entityManager->flush();

                unset($readProcesses[$project]);
                $output->writeln('<info>Analysis finished and saved for : '.$project.'</info>');
            }
        } while ($aProcessIsStillRunning);
    }
}
