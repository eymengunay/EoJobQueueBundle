<?php

namespace Eo\JobQueueBundle\Console;

declare(ticks = 10000000);

use Doctrine\DBAL\Types\Type;

use Eo\JobQueueBundle\Document\JobStatistic;
use Symfony\Bundle\FrameworkBundle\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\FlattenException;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Records debugging information for executed commands.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Application extends BaseApplication
{
    private $insertStatStmt;
    private $input;
    private $dm;

    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        $this->getDefinition()->addOption(new InputOption('--eo-job-id', null, InputOption::VALUE_REQUIRED, 'The ID of the Job.'));

        $kernel->boot();

        $this->dm = $this->getDocumentManager();
        if ($kernel->getContainer()->getParameter('eo_job_queue.statistics')) {
            register_tick_function(array($this, 'onTick'));
        }
    }

    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;

        try {
            $rs = parent::doRun($input, $output);
            $this->saveDebugInformation();

            return $rs;
        } catch (\Exception $ex) {
            $this->saveDebugInformation($ex);

            throw $ex;
        }
    }

    public function onTick()
    {
        if ( ! $this->input->hasOption('eo-job-id') || null === $jobId = $this->input->getOption('eo-job-id')) {
            return;
        }

        $characteristics = array(
            'memory' => memory_get_usage(),
        );

        foreach ($characteristics as $name => $value) {
            $stat = new JobStatistic();

            $stat->setJobId($jobId);
            $stat->setCreatedAt(new \DateTime());
            $stat->setCharacteristic($name);
            $stat->setCharValue($value);

            $this->dm->persist($stat);
        }
        $this->dm->flush();
    }

    private function saveDebugInformation(\Exception $ex = null)
    {
        if ( ! $this->input->hasOption('eo-job-id') || null === $jobId = $this->input->getOption('eo-job-id')) {
            return;
        }

        $this->getQueryBuilder()
            ->findAndUpdate()
            ->field('id')->equals($jobId)
            ->field('memoryUsage')->set(memory_get_peak_usage())
            ->field('memoryUsageReal')->set(memory_get_peak_usage(true))
            ->field('stackTrace')->set(serialize($ex ? FlattenException::create($ex) : null))
            ->getQuery()
            ->execute();
        ;
    }

    private function getQueryBuilder()
    {
        return $this->getDocumentManager()->createQueryBuilder($this->getKernel()->getContainer()->getParameter('eo_job_queue.job_class'));
    }

    private function getDocumentManager()
    {
        return $this->getKernel()->getContainer()->get('doctrine.odm.mongodb.document_manager');
    }
}
