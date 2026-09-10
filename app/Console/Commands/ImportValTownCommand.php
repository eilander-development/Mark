<?php

namespace App\Console\Commands;

use App\Services\ValTownImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportValTownCommand extends Command
{
    protected $signature = 'ironforge:import-val {--preview : Alleen dump-samenvatting tonen} {--force : Overslaan van bevestiging}';

    protected $description = 'Lees de Val Town dump (GET) en zet rijen in de database';

    public function handle(ValTownImporter $importer): int
    {
        try {
            if ($this->option('preview')) {
                $preview = $importer->preview();
                $this->table(
                    ['updatedAt', 'completedSets', 'weeks', 'currentWeek', 'cycle'],
                    [[
                        $preview['updatedAt'] ?? '-',
                        $preview['completedSets'],
                        $preview['weeks'],
                        $preview['currentWeek'] ?? 1,
                        $preview['cycle'] ?? 1,
                    ]],
                );

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->confirm('Val Town-dump in de database zetten? Bestaande huidige cyclus wordt overschreven.')) {
                return self::SUCCESS;
            }

            $result = $importer->import(true);
            $this->info(sprintf(
                'Geïmporteerd: %d voltooide sets over %d weken (updatedAt %s).',
                $result['completedSets'],
                $result['weeks'],
                $result['updatedAt'] ?? '-',
            ));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
