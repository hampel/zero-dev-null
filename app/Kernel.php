<?php

namespace App;

use Illuminate\Console\Application as Artisan;
use LaravelZero\Framework\Kernel as BaseKernel;
use ReflectionProperty;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Makes `commands.remove` apply to lazily loaded commands as well.
 *
 * Laravel Zero filters the removed classes out of the commands already instantiated. A command
 * class carrying #[AsCommand] - every laravel/mcp command - is never instantiated at that point:
 * Artisan only records its name in a command map and loads it on first use, so the filter does
 * not see it and the command stays listed and runnable.
 *
 * By the time this runs a removed command can be in two places, and it is taken out of both:
 *
 * - the command map, from which the framework builds the loader again after every starting
 *   callback has run - so filtering the loader alone is undone;
 * - the instantiated commands, because a Laravel Zero callback before this one lists every
 *   command to apply `commands.hidden`, and listing builds each lazy command from the loader.
 *
 * Registered after parent::commands(), so it runs after every callback Laravel Zero adds.
 */
class Kernel extends BaseKernel
{
    protected function commands(): void
    {
        parent::commands();

        /** @var list<class-string> $remove */
        $remove = $this->app['config']->get('commands.remove', []);

        if ($remove === []) {
            return;
        }

        $kept = fn (object|string $command): bool => ! in_array(
            is_object($command) ? get_class($command) : $command, $remove, true,
        );

        Artisan::starting(function (Artisan $artisan) use ($kept) {
            // The map is protected on Artisan; filter it from inside Artisan's own scope.
            (function () use ($kept) {
                $this->commandMap = array_filter($this->commandMap, $kept);
            })->call($artisan);

            // The instantiated commands are private to Symfony's Application, which is how
            // Laravel Zero's own filter reaches them too.
            $commands = new ReflectionProperty(SymfonyApplication::class, 'commands');
            $commands->setValue($artisan, array_filter((array) $commands->getValue($artisan), $kept));

            $artisan->setContainerCommandLoader();
        });
    }
}
