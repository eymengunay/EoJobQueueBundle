# EoJobQueueBundle

[![Dependencies Status](https://d2xishtp1ojlk0.cloudfront.net/d/9544114)](http://depending.in/eymengunay/EoJobQueueBundle)

Mongodb ODM implementation for [JMSJobQueueBundle](http://jmsyst.com/bundles/JMSJobQueueBundle) which allows to schedule Symfony2 console commands as jobs.

## Prerequisites
This version of the bundle requires Symfony 2.1+

## Installation

### Step 1: Download EoJobQueueBundle using composer
Add EoJobQueueBundle in your composer.json:
```
{
    "require": {
        "eo/job-queue-bundle": "dev-master"
    }
}
```

Now tell composer to download the bundle by running the command:
```
$ php composer.phar update eo/job-queue-bundle
```
Composer will install the bundle to your project's vendor/eo directory.

### Step 2: Enable the bundle
Enable the bundle in the kernel:
```
<?php
// app/AppKernel.php

public function registerBundles()
{
    $bundles = array(
        // ...
        new Eo\EoJobQueueBundle\EoJobQueueBundle(),
    );
}
```

### Step 3: Change console base application class
Have your `app/console` use EoJobQueueBundle's `Application`:    
```
// use Symfony\Bundle\FrameworkBundle\Console\Application;
use Eo\JobQueueBundle\Console\Application;
```

### Step 4 (Optional): Configure bundle
Now that you have properly installed and enabled EoJobQueueBundle, the next step is to configure the bundle to work with the specific needs of your application.

To change the default job class used by the bundle, add the following configuration to your `config.yml` file
```
# app/config/config.yml
eo_job_queue:
    job_class: JuliusJobBundle:Job
```

### Setting Up supervisord
For this bundle to work, you have to make sure that one (and only one)
instance of the console command `eo-job-queue:run` is running at all
times. You can easily achieve this by using [supervisord](http://supervisord.org/).

A sample supervisord config might look like this:

```
[program:eo_job_queue_runner]
command=php %kernel.root_dir%/console eo-job-queue:run --env=prod --verbose
process_name=%(program_name)s
numprocs=1
directory=/tmp
autostart=true
autorestart=true
startsecs=5
startretries=10
user=www-data
redirect_stderr=false
stdout_logfile=%capistrano.shared_dir%/eo_job_queue_runner.out.log
stdout_capture_maxbytes=1MB
stderr_logfile=%capistrano.shared_dir%/eo_job_queue_runner.error.log
stderr_capture_maxbytes=1MB
```

> For testing, or development, you can of course also run the command manually,
> but it will auto-exit after 15 minutes by default (you can change this with
> the `--max-runtime=seconds` option).

## Usage

### Creating Jobs
Creating jobs is super simple, you just need to persist an instance of `Job`:

```
<?php

$job = new Job('my-symfony2:command', array('some-args', 'or', '--options="foo"'));
$dm->persist($job);
$dm->flush($job);
```

### Adding Dependencies Between Jobs
If you want to have a job run after another job finishes, you can also achieve this
quite easily:

```
<?php

$job = new Job('a');
$dependentJob = new Job('b');
$dependentJob->addJobDependency($job);
$dm->persist($job);
$dm->persist($dependentJob);
$dm->flush();
```

### Adding Related Documents to Jobs
If you want to link a job to another document, for example to find the job more
easily, the job provides a special many-to-any association:

```
<?php

$job = new Job('a');
$job->addRelatedDocument($anyDocument);
$dm->persist($job);
$dm->flush();

$dm->getRepository('EoJobQueueBundle:Job')->findJobForRelatedDocument('a', $anyDocument);
```

### Schedule a Jobs
If you want to schedule a job :

```
<?php

$job = new Job('a');
$date = new DateTime();
$date->add(new DateInterval('PT30M'));
$job->setExecuteAfter($date);
$dm->persist($job);
$dm->flush();
```

## License
This bundle is under the Apache2 license. See the complete license in the bundle:
```
Resources/meta/LICENSE
```

## Reporting an issue or a feature request
Issues and feature requests related to this bundle are tracked in the Github issue tracker https://github.com/eymengunay/EoJobQueueBundle/issues
