<?php

namespace App\Domain\Content;

use App\Domain\Account\DisplayName;
use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** /api/reviews ve /api/reviews/mine yazma/okuma kurallari. */
final class Reviews
{
    /**
     * `$v2` (v2-03): her yoruma yazar kisa adi, ziyaret ayi, hizmet, yeni puanlar ve yayim ani eklenir; v1 ayni kalir.
     *
     * @return array{reviews:list<array<string,mixed>>,nextCursor:?string,count:int,average:int|float|null}
     */
    public static function publicPage(string $businessId, ?string $after, bool $v2 = false): array
    {
        $business = Rows::business($businessId);
        if ($business === null || ! (bool) $business->published || (bool) $business->sample) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }
        $q = DB::table('public_reviews')->where('business_id', $businessId)->orderBy('review_id')->limit(21);
        if ($after !== null) {
            $q->where('review_id', '>', $after);
        }
        $page = $q->get()->all();
        $visible = array_slice($page, 0, 20);
        $count = (int) $business->review_count;

        $authors = $v2 ? self::authors(array_map(static fn (\stdClass $d): string => $d->review_id, $visible)) : [];

        return [
            'reviews' => array_map(static fn (\stdClass $d): array => Policy::publicReview($d->review_id, Docs::publicReview($d)) + ($v2 ? self::publicExtra($d, $authors) : []), $visible),
            'nextCursor' => count($page) > 20 ? end($visible)->review_id : null,
            'count' => $count,
            'average' => $count > 0 ? (int) $business->review_rating_sum / $count : null,
        ];
    }

    /**
     * v2-03: yorum kimligi => yazarin kisa adi ("Selin A."). Ad yoksa anahtar yok; e-posta asla kullanilmaz.
     *
     * @param  list<string>  $reviewIds
     * @return array<string,string>
     */
    public static function authors(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $rows = DB::table('reviews as r')->join('auth_accounts as a', 'a.id', '=', 'r.user_id')->whereIn('r.id', $reviewIds)->get(['r.id', 'a.display_name']);
        $out = [];
        foreach ($rows as $row) {
            $short = DisplayName::short($row->display_name);
            if ($short !== null) {
                $out[$row->id] = $short;
            }
        }

        return $out;
    }

    /**
     * v2-03 public_reviews ek alanlari. Yeni puanlar v1 stiliyle yoksa yazilmaz; diger alanlar daima var (null olabilir).
     *
     * @param  array<string,string>  $authors
     * @return array<string,mixed>
     */
    public static function publicExtra(\stdClass $p, array $authors): array
    {
        $at = $p->published_at ?? null;

        return ['author' => isset($authors[$p->review_id]) ? ['displayName' => $authors[$p->review_id]] : null]
            + self::v2Fields($p)
            + ['publishedAtMs' => $at === null ? null : (int) CarbonImmutable::parse((string) $at, 'UTC')->getPreciseTimestamp(3)];
    }

    /**
     * reviews veya public_reviews satirindan v2 alanlari.
     *
     * @return array<string,mixed>
     */
    public static function v2Fields(\stdClass $row): array
    {
        $out = [];
        foreach (['communication' => 'communication', 'priceTransparency' => 'price_transparency'] as $key => $column) {
            if (($row->{$column} ?? null) !== null) {
                $out[$key] = (int) $row->{$column};
            }
        }
        $service = $row->service ?? null;
        $month = $row->visit_month ?? null;

        return $out + ['service' => is_string($service) && $service !== '' ? $service : null, 'visitMonth' => is_string($month) && $month !== '' ? $month : null];
    }

    /** @param array<string,mixed> $v reviewSchema ciktisi */
    private static function fields(array $v): array
    {
        $service = $v['service'] ?? '';

        return [
            'rating' => $v['rating'],
            'quality' => $v['quality'] ?? null,
            'payment' => $v['payment'] ?? null,
            'cleanliness' => $v['cleanliness'] ?? null,
            'wc_cleanliness' => $v['wcCleanliness'] ?? null,
            'resolved' => $v['resolved'],
            'wc' => $v['wc'],
            'text' => $v['text'],
            // v2-03: istege bagli alanlar; duzenlemede gonderilmezse temizlenir (tx.set).
            'communication' => $v['communication'] ?? null,
            'price_transparency' => $v['priceTransparency'] ?? null,
            'service' => $service === '' ? null : $service,
            'visit_month' => $v['visitMonth'] ?? null,
        ];
    }

    /** @param array<string,mixed> $v */
    public static function create(Actor $u, array $v): string
    {
        $id = Ids::sha([$u->uid, $v['businessId']]);
        $automatic = Moderation::columns($v['text']);
        Barrier::content($u->uid, ['kind' => 'actor'], static function () use ($u, $v, $id, $automatic): void {
            $b = Docs::business(Rows::business($v['businessId'], true));
            $member = Docs::membership(Rows::member($u->uid, $v['businessId'], true));
            $existing = Rows::review($id, true);
            if (($b['published'] ?? null) !== true || ($b['sample'] ?? null) === true) {
                throw new HttpError(404, 'İşletme bulunamadı.');
            }
            if (Policy::affiliatedReviewer($u->uid, $b, $member)) {
                throw new HttpError(403, 'Yetkilisi olduğun işletmeyi puanlayamazsın.');
            }
            if ($existing !== null) {
                throw new HttpError(409, 'Bu işletme için yorumun zaten var.');
            }
            $version = 1;
            Rows::create('reviews', ['id' => $id, 'business_id' => $v['businessId'], 'user_id' => $u->uid, 'status' => 'pending', 'version' => $version, 'submission_version' => $version, 'created_at' => Ids::now()] + self::fields($v) + $automatic,
                static function (): never {
                    throw new HttpError(409, 'Bu işletme için yorumun zaten var.');
                });
            Rows::history('review_history', 'review_id', $id, ['kind' => 'submitted', 'version' => $version, 'actor_id' => $u->uid, 'created_at' => Ids::now()]);
        });

        return $id;
    }

    /** @return array<string,mixed>|null `$v2`: yeni puanlar, hizmet ve ziyaret ayi eklenir. */
    public static function own(Actor $u, string $businessId, bool $v2 = false): ?array
    {
        $id = Ids::sha([$u->uid, $businessId]);
        $row = Rows::review($id);
        $view = Policy::ownReview($u->uid, $businessId, $id, Docs::review($row));

        return $view !== null && $v2 && $row !== null ? $view + self::v2Fields($row) : $view;
    }

    /**
     * web-17: yazarin butun yorumlari, son guncellenen once, sayfa 20. `review` nesnesi `reviews/mine` ile ayni projeksiyon.
     * Imlec: base64url({t:siralama zamani,i:kimlik}).
     *
     * @return array{items:list<array<string,mixed>>,next:?string}
     */
    public static function ownAll(Actor $u, ?array $cursor, bool $v2 = false): array
    {
        $sort = 'COALESCE(updated_at, created_at)';
        $q = DB::table('reviews')->where('user_id', $u->uid)->selectRaw('*, '.$sort.' as sort_at');
        if ($cursor !== null) {
            $q->where(fn ($w) => $w->whereRaw($sort.' < ?', [$cursor['t']])->orWhere(fn ($x) => $x->whereRaw($sort.' = ?', [$cursor['t']])->where('id', '>', $cursor['i'])));
        }
        $rows = $q->orderByRaw($sort.' desc')->orderBy('id')->limit(21)->get();
        $page = $rows->take(20);
        $records = \App\Domain\Catalogue\BusinessReader::records($page->pluck('business_id')->unique()->values()->all());
        $items = [];
        foreach ($page as $row) {
            $review = Policy::ownReview($u->uid, $row->business_id, $row->id, Docs::review($row));
            if ($review === null) {
                continue;
            }
            $record = $records[$row->business_id] ?? null;
            $items[] = [
                'businessId' => $row->business_id, 'businessName' => $record['name'] ?? '',
                'businessAvailable' => $record !== null && \App\Domain\Catalogue\BusinessReader::publicBusiness($row->business_id, $record) !== null,
                'updatedAtMs' => (int) (\Carbon\CarbonImmutable::parse((string) $row->sort_at, 'UTC')->getPreciseTimestamp(3)),
                'review' => $v2 ? $review + self::v2Fields($row) : $review,
            ];
        }
        $last = $page->last();
        $next = $rows->count() > 20 && $last !== null ? rtrim(strtr(base64_encode((string) json_encode(['t' => (string) $last->sort_at, 'i' => $last->id])), '+/', '-_'), '=') : null;

        return ['items' => $items, 'next' => $next];
    }

    /** @param array<string,mixed> $input authorReviewAction ciktisi */
    public static function change(Actor $u, array $input): string
    {
        $id = Ids::sha([$u->uid, $input['businessId']]);
        Barrier::content($u->uid, ['kind' => 'review_with_reply', 'id' => $id], static function () use ($u, $input, $id): void {
            $row = Rows::review($id, true);
            $businessRow = Rows::business($input['businessId'], true);
            $b = Docs::business($businessRow);
            $m = Docs::membership(Rows::member($u->uid, $input['businessId'], true));
            $p = Rows::publicRow($input['businessId'], $id, true);
            $replyRow = Rows::reply($id, true);
            $previous = Docs::review($row);
            try {
                $transition = Policy::authorReviewTransition($u->uid, $input['businessId'], $previous, $input['expectedVersion'], $input['action']);
            } catch (RuntimeException $e) {
                throw new HttpError(409, $e->getMessage());
            }
            if ($input['action'] === 'edit' && (($b['published'] ?? null) !== true || ($b['sample'] ?? null) === true || Policy::affiliatedReviewer($u->uid, $b, $m))) {
                throw new HttpError(403, 'İşletme veya yorumcu yayın koşullarını karşılamıyor.');
            }
            if (($previous['status'] === 'approved') !== ($p !== null)) {
                throw new HttpError(409, 'Yorum yayın kaydı tutarsız. Destek ekibine başvur.');
            }
            if ($transition['delta'] === -1) {
                if ($businessRow === null) {
                    throw new HttpError(409, 'İşletme bulunamadı.');
                }
                $totals = Policy::reviewTotals((int) $businessRow->review_count, (int) $businessRow->review_rating_sum, (int) $previous['rating'], -1);
                DB::table('businesses')->where('id', $input['businessId'])->update(['review_count' => $totals['reviewCount'], 'review_rating_sum' => $totals['reviewRatingSum']]);
            }
            DB::table('public_reviews')->where('business_id', $input['businessId'])->where('review_id', $id)->delete();
            \App\Domain\Catalogue\ReviewSummary::refresh($input['businessId']); // web-08
            if ($replyRow !== null && $replyRow->business_id === $input['businessId']) {
                // v2-03: yeni yorum surumu icin yanit duzenleme hakki sifirlanir.
                DB::table('review_replies')->where('review_id', $id)->update(['status' => 'hidden', 'version' => (int) $replyRow->version + 1, 'reason' => 'Ana yorum değişti veya geri çekildi.', 'first_published_at_ms' => null, 'edit_count' => 0, 'updated_at' => Ids::now()]);
                Rows::history('review_reply_history', 'review_id', $id, ['kind' => 'parent_changed', 'actor_id' => $u->uid, 'details' => Ids::stringify(['reviewVersion' => $transition['version']]), 'created_at' => Ids::now()]);
            }
            Rows::history('review_history', 'review_id', $id, ['kind' => $input['action'] === 'edit' ? 'author_edited' : 'author_withdrawn', 'version' => $transition['version'], 'actor_id' => $u->uid, 'details' => Ids::stringify(['before' => Policy::ownReview($u->uid, $input['businessId'], $id, $previous)]), 'created_at' => Ids::now()]);
            if ($input['action'] === 'edit') {
                // tx.set: yayin/inceleme alanlari da sifirlanir, yalniz createdAt korunur.
                DB::table('reviews')->where('id', $id)->update(['status' => 'pending', 'version' => $transition['version'], 'submission_version' => $transition['submissionVersion'], 'moderation_hold' => false, 'reason' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'withdrawn_at' => null, 'photo_attempts' => 0, 'updated_at' => Ids::now()] + self::fields($input['review']) + Moderation::columns($input['review']['text']));
            } else {
                DB::table('reviews')->where('id', $id)->update(['status' => 'withdrawn', 'version' => $transition['version'], 'submission_version' => $transition['submissionVersion'], 'moderation_hold' => $transition['moderationHold'], 'withdrawn_at' => Ids::now()]);
            }
            DB::table('review_photo_refs')->where('review_id', $id)->delete();
        });

        return $input['action'] === 'edit' ? 'pending' : 'withdrawn';
    }
}
