<?php

namespace App\Console\Commands;

use App\Support\CountryPack;
use Illuminate\Console\Command;

// Shows or applies a country pack from the command line, for a new installation.
class IspCountryPack extends Command
{
    protected $signature = 'isp:country-pack {country? : ISO code, e.g. BD, IN, US} {--apply : apply the pack to the company}
        {--tax : also set the tax name, pricing and suggested tax rates} {--billing : also set every branch\'s billing defaults}';

    protected $description = 'List country packs, show one, or apply it to the company';

    public function handle(): int
    {
        $code = strtoupper((string) $this->argument('country'));
        if ($code === '') {
            $this->table(['Code', 'Country', 'Currency', 'Timezone', 'Pack'], collect(config('countries'))
                ->map(fn ($c, $code) => [$code, $c['name'], $c['currency'], $c['timezone'], CountryPack::hasFullPack($code) ? 'full' : 'generic'])->values());
            return self::SUCCESS;
        }
        if (! CountryPack::exists($code)) {
            $this->error("Unknown country: $code");
            return self::FAILURE;
        }

        $pack = CountryPack::get($code);
        $this->line(json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($this->option('apply')) {
            foreach (CountryPack::apply($code, (bool) $this->option('tax'), (bool) $this->option('billing')) as $line) {
                $this->info($line);
            }
        }
        return self::SUCCESS;
    }
}
