<?php

namespace App\Panel\WorkItems;

/**
 * Is kuyrugu kaynaklari: ana API tablolari ve "acik" durumlari. panel:work-items-sync ve
 * karar sonrasi WorkItems::refresh bu tanimdan okur. Kaynak kayit degistirilmez.
 */
final class Queues
{
    /**
     * @return array<string,array{label:string,table:string,key:string,state:string,open:list<string>,business:?string,permission:string,subject:string}>
     */
    public static function all(): array
    {
        return [
            'claims' => ['label' => 'Sahiplik başvurusu', 'table' => 'claims', 'key' => 'id', 'state' => 'status', 'open' => ['submitted', 'needs_information'], 'business' => 'business_id', 'permission' => 'claims.review', 'subject' => 'claim'],
            'reviews' => ['label' => 'Yorum', 'table' => 'reviews', 'key' => 'id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'reviews.moderate', 'subject' => 'review'],
            'replies' => ['label' => 'İşletme yanıtı', 'table' => 'review_replies', 'key' => 'review_id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'replies.moderate', 'subject' => 'reply'],
            'review_cases' => ['label' => 'Yorum bildirimi/itirazı', 'table' => 'review_cases', 'key' => 'id', 'state' => 'status', 'open' => ['open'], 'business' => 'business_id', 'permission' => 'review_cases.moderate', 'subject' => 'review_case'],
            'review_photos' => ['label' => 'Yorum fotoğrafı', 'table' => 'review_photos', 'key' => 'id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'review_photos.moderate', 'subject' => 'review_photo'],
            'business_photos' => ['label' => 'İşletme fotoğrafı', 'table' => 'business_photos', 'key' => 'id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'business_photos.moderate', 'subject' => 'business_photo'],
            'business_changes' => ['label' => 'Profil değişikliği', 'table' => 'business_changes', 'key' => 'business_id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'business_changes.review', 'subject' => 'business_change'],
            'business_reports' => ['label' => 'Yanlış bilgi bildirimi', 'table' => 'business_reports', 'key' => 'id', 'state' => 'status', 'open' => ['submitted'], 'business' => 'business_id', 'permission' => 'reports.review', 'subject' => 'business_report'],
            'feature_activations' => ['label' => 'Teklif/randevu başvurusu', 'table' => 'feature_activations', 'key' => 'business_id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'activations.review', 'subject' => 'feature_activation'],
            'privacy_requests' => ['label' => 'Silme/gizlilik talebi', 'table' => 'privacy_requests', 'key' => 'id', 'state' => 'status', 'open' => ['submitted', 'reviewing', 'needs_confirmation'], 'business' => null, 'permission' => 'privacy.manage', 'subject' => 'privacy_request'],
            'privacy_exports' => ['label' => 'Veri kopyası talebi', 'table' => 'privacy_exports', 'key' => 'id', 'state' => 'status', 'open' => ['submitted', 'in_progress'], 'business' => null, 'permission' => 'privacy.manage', 'subject' => 'privacy_export'],
            'ad_campaigns' => ['label' => 'Reklam kampanyası', 'table' => 'ad_campaigns', 'key' => 'id', 'state' => 'status', 'open' => ['pending'], 'business' => 'business_id', 'permission' => 'ads.moderate', 'subject' => 'ad_campaign'],
            'brand_campaigns' => ['label' => 'Marka kampanyası', 'table' => 'brand_campaigns', 'key' => 'id', 'state' => 'status', 'open' => ['pending'], 'business' => null, 'permission' => 'finance.brand', 'subject' => 'brand_campaign'],
            'ad_payment_cases' => ['label' => 'Reklam ödeme uyuşmazlığı', 'table' => 'ad_payment_cases', 'key' => 'id', 'state' => 'status', 'open' => ['open', 'reviewing', 'refund_required'], 'business' => null, 'permission' => 'finance.payment_cases', 'subject' => 'ad_payment_case'],
            'brand_payment_cases' => ['label' => 'Marka ödeme incelemesi', 'table' => 'brand_payment_cases', 'key' => 'id', 'state' => 'status', 'open' => ['open', 'reviewing', 'refund_required'], 'business' => null, 'permission' => 'finance.brand_review', 'subject' => 'brand_payment_case'],
            'brand_cancellations' => ['label' => 'Marka iptal talebi', 'table' => 'brand_cancellations', 'key' => 'reservation_id', 'state' => 'status', 'open' => ['pending'], 'business' => null, 'permission' => 'finance.refunds', 'subject' => 'brand_cancellation'],
            'notification_jobs' => ['label' => 'Başarısız bildirim', 'table' => 'notification_jobs', 'key' => 'id', 'state' => 'state', 'open' => ['failed'], 'business' => 'business_id', 'permission' => 'notifications.retry', 'subject' => 'notification_job'],
        ];
    }

    /** @return array{label:string,table:string,key:string,state:string,open:list<string>,business:?string,permission:string,subject:string} */
    public static function get(string $queue): array
    {
        $all = self::all();
        if (! isset($all[$queue])) {
            throw new \InvalidArgumentException('Unknown queue');
        }

        return $all[$queue];
    }
}
