<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Proposes which TCGplayer group(s) each catalog set pulls prices from,
 * by the set's collector abbreviation. Prints by default; nothing fuzzy is
 * written on its own — `--write` stores only sets with exactly one match,
 * and an ambiguous set is stored with an explicit `--set` and `--group`.
 */
final class ProposeTcgplayerGroups extends Command
{
    protected $signature = 'catalog:propose-tcgplayer-groups
        {--write : Store the sets that have exactly one matching group}
        {--set= : A set tcgdex id to map explicitly (with --group)}
        {--group= : The TCGplayer group id for --set}';

    protected $description = 'Propose (and optionally store) the TCGplayer price groups for each catalog set.';

    public function handle(TcgcsvClient $client): int
    {
        $groups = collect($client->groups());

        if ($this->option('set') !== null) {
            return $this->storeExplicit($groups->pluck('groupId')->values()->all());
        }

        $rows = [];
        foreach (Set::orderBy('tcgdex_id')->get() as $set) {
            $matches = $set->abbreviation === null ? collect() : $groups
                ->filter(fn (array $g) => $g['abbreviation'] !== null && strcasecmp($g['abbreviation'], $set->abbreviation) === 0)
                ->values();

            $status = match (true) {
                $set->abbreviation === null => 'no abbreviation',
                $matches->isEmpty() => 'no match',
                $matches->count() > 1 => 'ambiguous',
                default => 'match',
            };

            // Group names come from tcgcsv: escaped so console style tags in them render as text.
            $rows[] = [$set->tcgdex_id, $set->name, $set->abbreviation ?? '—', OutputFormatter::escape($matches->map(fn (array $g) => "{$g['groupId']} {$g['name']}".($g['isSupplemental'] ? ' (supplemental)' : ''))->implode('; ')), $status];

            if ($status === 'match' && $this->option('write')) {
                DB::table('set_tcgplayer_groups')->insertOrIgnore(['set_id' => $set->id, 'group_id' => $matches[0]['groupId']]);
            }
        }

        $this->table(['set', 'name', 'abbr', 'tcgplayer group(s)', 'status'], $rows);

        return self::SUCCESS;
    }

    /** @param  array<int, int>  $knownGroups */
    private function storeExplicit(array $knownGroups): int
    {
        $set = Set::where('tcgdex_id', $this->option('set'))->first();
        $group = (int) $this->option('group');

        if ($set === null || ! in_array($group, $knownGroups, true)) {
            $this->error('Unknown set or TCGplayer group.');

            return self::FAILURE;
        }

        DB::table('set_tcgplayer_groups')->insertOrIgnore(['set_id' => $set->id, 'group_id' => $group]);
        $this->info("{$set->tcgdex_id} → group {$group}");

        return self::SUCCESS;
    }
}
