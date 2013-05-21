<?php

namespace Eo\JobQueueBundle\Twig;

class JobQueueExtension extends \Twig_Extension
{
    private $linkGenerators = array();

    public function __construct(array $generators = array())
    {
        $this->linkGenerators = $generators;
    }

    public function getTests()
    {
        return array(
            'eo_job_queue_linkable' => new \Twig_Test_Method($this, 'isLinkable'),
        );
    }

    public function getFunctions()
    {
        return array(
            'eo_job_queue_path' => new \Twig_Function_Method($this, 'generatePath', array('is_safe' => array('html' => true))),
        );
    }

    public function getFilters()
    {
        return array(
            'eo_job_queue_linkname' => new \Twig_Filter_Method($this, 'getLinkname'),
            'eo_job_queue_args' => new \Twig_Filter_Method($this, 'formatArgs'),
        );
    }

    public function formatArgs(array $args, $maxLength = 60)
    {
        $str = '';
        $first = true;
        foreach ($args as $arg) {
            $argLength = strlen($arg);

            if ( ! $first) {
                $str .= ' ';
            }
            $first = false;

            if (strlen($str) + $argLength > $maxLength) {
                $str .= substr($arg, 0, $maxLength - strlen($str) - 4).'...';
                break;
            }

            $str .= $arg;
        }

        return $str;
    }

    public function isLinkable($document)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($document)) {
                return true;
            }
        }

        return false;
    }

    public function generatePath($document)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($document)) {
                return $generator->generate($document);
            }
        }

        throw new \RuntimeException(sprintf('The document "%s" has no link generator.', get_class($document)));
    }

    public function getLinkname($document)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($document)) {
                return $generator->getLinkname($document);
            }
        }

        throw new \RuntimeException(sprintf('The document "%s" has no link generator.', get_class($document)));
    }

    public function getName()
    {
        return 'eo_job_queue';
    }
}