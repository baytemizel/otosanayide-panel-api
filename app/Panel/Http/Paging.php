<?php

namespace App\Panel\Http;

use App\Exceptions\HttpError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/** Sayfa numarali listeler: ?page=1&perPage=25 (en fazla 100). */
final class Paging
{
    private function __construct(public readonly int $page, public readonly int $perPage) {}

    public static function from(Request $request): self
    {
        $page = $request->query('page', '1');
        $per = $request->query('perPage', (string) config('panel.pagination.default'));
        if (! is_string($page) || ! is_string($per) || preg_match('/^[1-9]\d{0,4}$/D', $page) !== 1 || preg_match('/^[1-9]\d{0,2}$/D', $per) !== 1) {
            throw new HttpError(400, 'Geçersiz sayfalama.');
        }

        return new self((int) $page, min((int) $per, (int) config('panel.pagination.max')));
    }

    /** @return array{0:list<\stdClass>,1:int} */
    public function run(Builder $query): array
    {
        $total = (clone $query)->reorder()->count();
        $rows = $query->forPage($this->page, $this->perPage)->get()->all();

        return [array_values($rows), $total];
    }
}
