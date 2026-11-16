<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GovernanceController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TaxonomyController;
use App\Http\Controllers\WorkController;
use App\Panel\Http\Respond;
use Illuminate\Support\Facades\Route;

/*
 * Panel REST API. Her rota (giris uclari ve saglik disinda) Bearer erisim jetonu ister (`panel`) ve
 * rota basina panel yetkisi tasir (`perm:`). Kimlik kurallari: [A-Za-z0-9_-]{1,120}; uuid; sha256.
 * Rota listesi docs/source/endpoints.json ile birebir eslesir (tests/Feature/RouteCatalogTest.php).
 */

$id = '[A-Za-z0-9_-]{1,120}';
$uid = '[A-Za-z0-9_-]{1,128}';
$uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
$sha = '[0-9a-f]{64}';

Route::get('health', fn () => Respond::data(['status' => 'ok']));

Route::prefix('v1')->group(function () use ($id, $uid, $uuid, $sha): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('mfa', [AuthController::class, 'mfa']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::middleware('panel')->group(function () use ($id, $uid, $uuid, $sha): void {
        // Oturum
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{familyId}', [AuthController::class, 'revokeSession'])->where('familyId', $uuid);

        // Genel bakis ve istatistik
        Route::get('dashboard', [DashboardController::class, 'dashboard'])->middleware('perm:dashboard.view');
        Route::get('dashboard/overview', [DashboardController::class, 'overview'])->middleware('perm:dashboard.view');
        Route::get('stats/catalogue', [DashboardController::class, 'catalogue'])->middleware('perm:stats.view');
        Route::get('stats/accounts', [DashboardController::class, 'accounts'])->middleware('perm:stats.view');
        Route::get('stats/snapshots', [DashboardController::class, 'snapshots'])->middleware('perm:stats.view');

        // Panel uyeleri, roller, ikinci kontrol, denetim, ayarlar
        Route::get('members', [MemberController::class, 'index'])->middleware('perm:members.view');
        Route::post('members', [MemberController::class, 'store'])->middleware('perm:members.manage');
        Route::get('members/{accountId}', [MemberController::class, 'show'])->where('accountId', $uid)->middleware('perm:members.view');
        Route::patch('members/{accountId}', [MemberController::class, 'update'])->where('accountId', $uid)->middleware('perm:members.manage');
        Route::put('members/{accountId}/roles', [MemberController::class, 'roles'])->where('accountId', $uid)->middleware('perm:members.manage');
        Route::post('members/{accountId}/suspend', [MemberController::class, 'suspend'])->where('accountId', $uid)->middleware('perm:members.manage');
        Route::post('members/{accountId}/reactivate', [MemberController::class, 'reactivate'])->where('accountId', $uid)->middleware('perm:members.manage');
        Route::post('members/{accountId}/mfa-reset', [MemberController::class, 'resetMfa'])->where('accountId', $uid)->middleware('perm:members.manage');
        Route::post('members/{accountId}/sessions/revoke', [MemberController::class, 'revokeSessions'])->where('accountId', $uid)->middleware('perm:members.manage');

        Route::get('roles', [RoleController::class, 'index'])->middleware('perm:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('perm:roles.manage');
        Route::patch('roles/{key}', [RoleController::class, 'update'])->where('key', '[a-z][a-z0-9_]{1,63}')->middleware('perm:roles.manage');
        Route::delete('roles/{key}', [RoleController::class, 'destroy'])->where('key', '[a-z][a-z0-9_]{1,63}')->middleware('perm:roles.manage');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('perm:roles.view');

        Route::get('approvals', [GovernanceController::class, 'approvals'])->middleware('perm:approvals.view');
        Route::get('approvals/{id}', [GovernanceController::class, 'approval'])->where('id', $uuid)->middleware('perm:approvals.view');
        Route::post('approvals/{id}/approve', [GovernanceController::class, 'approve'])->where('id', $uuid)->middleware('perm:approvals.decide');
        Route::post('approvals/{id}/reject', [GovernanceController::class, 'reject'])->where('id', $uuid)->middleware('perm:approvals.decide');
        Route::post('approvals/{id}/cancel', [GovernanceController::class, 'cancelApproval'])->where('id', $uuid);

        Route::get('audit-logs', [GovernanceController::class, 'auditLogs'])->middleware('perm:audit.view');
        Route::get('audit-logs/{id}', [GovernanceController::class, 'auditLog'])->where('id', '[1-9][0-9]{0,18}')->middleware('perm:audit.view');

        Route::get('settings', [GovernanceController::class, 'settings'])->middleware('perm:settings.view');
        Route::put('settings/{key}', [GovernanceController::class, 'updateSetting'])->where('key', '[a-z_]+\.[a-z_]+')->middleware('perm:settings.manage');

        // Is kuyrugu ve notlar
        Route::get('work-items', [WorkController::class, 'index'])->middleware('perm:work_items.view');
        Route::get('work-items/summary', [WorkController::class, 'summary'])->middleware('perm:work_items.view');
        Route::get('work-items/{id}', [WorkController::class, 'show'])->where('id', $uuid)->middleware('perm:work_items.view');
        Route::patch('work-items/{id}', [WorkController::class, 'update'])->where('id', $uuid)->middleware('perm:work_items.work|work_items.manage');
        Route::post('work-items/{id}/claim', [WorkController::class, 'claim'])->where('id', $uuid)->middleware('perm:work_items.work|work_items.manage');
        Route::post('work-items/{id}/release', [WorkController::class, 'release'])->where('id', $uuid)->middleware('perm:work_items.work|work_items.manage');
        Route::get('notes', [WorkController::class, 'notes'])->middleware('perm:notes.view');
        Route::post('notes', [WorkController::class, 'addNote'])->middleware('perm:notes.write');
        Route::delete('notes/{id}', [WorkController::class, 'deleteNote'])->where('id', $uuid)->middleware('perm:notes.write');

        // Platform hesaplari ve destek erisimi
        Route::get('accounts', [AccountController::class, 'index'])->middleware('perm:accounts.view');
        Route::get('accounts/{id}', [AccountController::class, 'show'])->where('id', $uid)->middleware('perm:accounts.view');
        Route::get('accounts/{id}/auth-events', [AccountController::class, 'events'])->where('id', $uid)->middleware('perm:accounts.view');
        Route::get('accounts/{id}/sessions', [AccountController::class, 'sessions'])->where('id', $uid)->middleware('perm:accounts.view');
        Route::get('accounts/{id}/reviews', [AccountController::class, 'reviews'])->where('id', $uid)->middleware('perm:accounts.view');
        Route::get('accounts/{id}/claims', [AccountController::class, 'claims'])->where('id', $uid)->middleware('perm:accounts.view');
        Route::post('accounts/{id}/disable', [AccountController::class, 'disable'])->where('id', $uid)->middleware('perm:accounts.manage');
        Route::post('accounts/{id}/enable', [AccountController::class, 'enable'])->where('id', $uid)->middleware('perm:accounts.manage');
        Route::post('accounts/{id}/sessions/revoke', [AccountController::class, 'revokeSessions'])->where('id', $uid)->middleware('perm:accounts.manage');
        Route::put('accounts/{id}/claims', [AccountController::class, 'claimsChange'])->where('id', $uid)->middleware('perm:accounts.claims');
        Route::get('accounts/{id}/garage', [AccountController::class, 'garage'])->where('id', $uid)->middleware('perm:support.access');
        Route::get('accounts/{id}/requests', [AccountController::class, 'requests'])->where('id', $uid)->middleware('perm:support.access');
        Route::get('accounts/{id}/notifications', [AccountController::class, 'notifications'])->where('id', $uid)->middleware('perm:support.access');
        Route::get('accounts/{id}/favorites', [AccountController::class, 'favorites'])->where('id', $uid)->middleware('perm:support.access');
        Route::get('support-access', [AccountController::class, 'grants'])->middleware('perm:support.access|support.manage');
        Route::post('support-access', [AccountController::class, 'grant'])->middleware('perm:support.access');
        Route::post('support-access/{id}/revoke', [AccountController::class, 'revokeGrant'])->where('id', $uuid)->middleware('perm:support.access|support.manage');

        // Isletmeler ve katalog
        Route::get('businesses', [BusinessController::class, 'index'])->middleware('perm:businesses.view');
        Route::get('businesses/{id}', [BusinessController::class, 'show'])->where('id', $id)->middleware('perm:businesses.view');
        Route::get('businesses/{id}/source', [BusinessController::class, 'source'])->where('id', $id)->middleware('perm:businesses.source');
        Route::get('businesses/{id}/members', [BusinessController::class, 'members'])->where('id', $id)->middleware('perm:businesses.view');
        Route::get('businesses/{id}/reviews', [BusinessController::class, 'reviews'])->where('id', $id)->middleware('perm:businesses.view');
        Route::get('businesses/{id}/reports', [BusinessController::class, 'reports'])->where('id', $id)->middleware('perm:businesses.view');
        Route::get('businesses/{id}/claims', [BusinessController::class, 'claims'])->where('id', $id)->middleware('perm:businesses.view|claims.view');
        Route::get('businesses/{id}/history', [BusinessController::class, 'history'])->where('id', $id)->middleware('perm:businesses.view');
        Route::get('businesses/{id}/draft', [BusinessController::class, 'draft'])->where('id', $id)->middleware('perm:businesses.edit');
        Route::put('businesses/{id}/draft', [BusinessController::class, 'saveDraft'])->where('id', $id)->middleware('perm:businesses.edit');
        Route::post('businesses/{id}/publication', [BusinessController::class, 'publication'])->where('id', $id)->middleware('perm:businesses.publish');
        Route::get('catalogue/queue', [BusinessController::class, 'queue'])->middleware('perm:businesses.view');

        // Sahiplik
        Route::get('claims', [ClaimController::class, 'index'])->middleware('perm:claims.view');
        Route::get('claims/targets/{businessId}', [ClaimController::class, 'target'])->where('businessId', $id)->middleware('perm:claims.review');
        Route::get('claims/{id}', [ClaimController::class, 'show'])->where('id', $id)->middleware('perm:claims.view');
        Route::post('claims/{id}/decision', [ClaimController::class, 'decide'])->where('id', $id)->middleware('perm:claims.review');
        Route::post('claims/{id}/target', [ClaimController::class, 'mapTarget'])->where('id', $id)->middleware('perm:claims.review');
        Route::post('claims/{id}/evidence-verification', [ClaimController::class, 'verifyEvidence'])->where('id', $id)->middleware('perm:claims.evidence');

        // Icerik moderasyonu
        Route::prefix('moderation')->group(function () use ($id, $uuid): void {
            Route::get('reviews', [ModerationController::class, 'reviews'])->middleware('perm:reviews.moderate');
            Route::get('reviews/{id}', [ModerationController::class, 'review'])->where('id', $id)->middleware('perm:reviews.moderate');
            Route::post('reviews/{id}/decision', [ModerationController::class, 'decideReview'])->where('id', $id)->middleware('perm:reviews.moderate');
            Route::post('reviews/{id}/ai-analysis', [ModerationController::class, 'analyze'])->where('id', $id)->middleware('perm:reviews.ai');
            Route::get('replies', [ModerationController::class, 'replies'])->middleware('perm:replies.moderate');
            Route::post('replies/{reviewId}/decision', [ModerationController::class, 'decideReply'])->where('reviewId', $id)->middleware('perm:replies.moderate');
            Route::get('review-cases', [ModerationController::class, 'cases'])->middleware('perm:review_cases.moderate');
            Route::post('review-cases/{id}/decision', [ModerationController::class, 'decideCase'])->where('id', $id)->middleware('perm:review_cases.moderate');
            Route::get('review-photos', [ModerationController::class, 'reviewPhotos'])->middleware('perm:review_photos.moderate');
            Route::post('review-photos/{id}/decision', [ModerationController::class, 'decideReviewPhoto'])->where('id', $uuid)->middleware('perm:review_photos.moderate');
            Route::get('business-photos', [ModerationController::class, 'businessPhotos'])->middleware('perm:business_photos.moderate');
            Route::post('business-photos/{id}/decision', [ModerationController::class, 'decideBusinessPhoto'])->where('id', $uuid)->middleware('perm:business_photos.moderate');
            Route::get('business-changes', [ModerationController::class, 'businessChanges'])->middleware('perm:business_changes.review');
            Route::post('business-changes/{businessId}/decision', [ModerationController::class, 'decideBusinessChange'])->where('businessId', $id)->middleware('perm:business_changes.review');
            Route::get('business-reports', [ModerationController::class, 'reports'])->middleware('perm:reports.review');
            Route::post('business-reports/{id}/decision', [ModerationController::class, 'decideReport'])->where('id', $uuid)->middleware('perm:reports.review');
            Route::get('feature-activations', [ModerationController::class, 'activations'])->middleware('perm:activations.review');
            Route::get('feature-activations/{businessId}', [ModerationController::class, 'activation'])->where('businessId', $id)->middleware('perm:activations.review');
            Route::post('feature-activations/{businessId}/decision', [ModerationController::class, 'decideActivation'])->where('businessId', $id)->middleware('perm:activations.review');
        });

        // Gizlilik ve bildirim isleri
        Route::get('privacy/requests', [PrivacyController::class, 'requests'])->middleware('perm:privacy.view');
        Route::post('privacy/requests/{id}/action', [PrivacyController::class, 'requestAction'])->where('id', $uuid)->middleware('perm:privacy.manage');
        Route::get('privacy/exports', [PrivacyController::class, 'exports'])->middleware('perm:privacy.view');
        Route::post('privacy/exports/{id}/decision', [PrivacyController::class, 'exportDecision'])->where('id', $uuid)->middleware('perm:privacy.manage');
        Route::get('notification-jobs', [PrivacyController::class, 'notificationJobs'])->middleware('perm:notifications.view');
        Route::get('notification-jobs/{id}', [PrivacyController::class, 'notificationJob'])->where('id', '[A-Za-z0-9_-]{1,180}')->middleware('perm:notifications.view');
        Route::post('notification-jobs/{id}/retry', [PrivacyController::class, 'retryNotification'])->where('id', '[A-Za-z0-9_-]{1,180}')->middleware('perm:notifications.retry');

        // Isletme reklami ve finans
        Route::get('ads/campaigns', [AdsController::class, 'campaigns'])->middleware('perm:ads.view');
        Route::post('ads/campaigns/{id}/decision', [AdsController::class, 'decideCampaign'])->where('id', $uuid)->middleware('perm:ads.moderate');
        Route::get('ads/tariffs', [AdsController::class, 'tariff'])->middleware('perm:ads.view');
        Route::put('ads/tariffs', [AdsController::class, 'saveTariff'])->middleware('perm:ads.tariffs');
        Route::get('finance/payment-cases', [AdsController::class, 'paymentCases'])->middleware('perm:finance.payment_cases');
        Route::post('finance/payment-cases/{id}/decision', [AdsController::class, 'decidePaymentCase'])->where('id', $sha)->middleware('perm:finance.payment_cases');
        Route::get('finance/brand-payment-review', [AdsController::class, 'brandPaymentReview'])->middleware('perm:finance.brand_review');
        Route::post('finance/brand-payment-review/{id}/decision', [AdsController::class, 'decideBrandPaymentReview'])->where('id', $sha)->middleware('perm:finance.brand_review');

        // Marka reklami
        Route::prefix('brand')->group(function () use ($uuid): void {
            Route::get('tariffs', [BrandController::class, 'tariff'])->middleware('perm:finance.brand');
            Route::put('tariffs', [BrandController::class, 'saveTariff'])->middleware('perm:finance.brand');
            Route::put('capacities', [BrandController::class, 'saveCapacity'])->middleware('perm:finance.brand');
            Route::post('quotes', [BrandController::class, 'quote'])->middleware('perm:finance.brand');
            Route::get('campaigns', [BrandController::class, 'campaigns'])->middleware('perm:finance.brand');
            Route::post('campaigns/{id}/actions', [BrandController::class, 'campaignAction'])->where('id', $uuid)->middleware('perm:finance.brand');
            Route::get('campaigns/{id}/metrics', [BrandController::class, 'metrics'])->where('id', $uuid)->middleware('perm:finance.brand');
            Route::get('reservations', [BrandController::class, 'reservation'])->middleware('perm:finance.brand');
            Route::post('reservations', [BrandController::class, 'reserve'])->middleware('perm:finance.brand');
            Route::delete('reservations/{campaignId}', [BrandController::class, 'release'])->where('campaignId', $uuid)->middleware('perm:finance.brand');
            Route::get('orders', [BrandController::class, 'order'])->middleware('perm:finance.brand');
            Route::post('orders', [BrandController::class, 'prepareOrder'])->middleware('perm:finance.brand');
            Route::get('acceptances', [BrandController::class, 'acceptance'])->middleware('perm:finance.brand');
            Route::post('acceptances', [BrandController::class, 'accept'])->middleware('perm:finance.brand');
            Route::get('cancellations', [BrandController::class, 'cancellation'])->middleware('perm:finance.refunds');
            Route::post('cancellations', [BrandController::class, 'requestCancellation'])->middleware('perm:finance.refunds');
            Route::post('cancellations/decision', [BrandController::class, 'decideCancellation'])->middleware('perm:finance.refunds');
            Route::get('cancellations/queue', [BrandController::class, 'cancellationQueue'])->middleware('perm:finance.refunds');
            Route::get('cancellations/history', [BrandController::class, 'cancellationHistory'])->middleware('perm:finance.refunds');
            Route::get('refund-reviews', [BrandController::class, 'refundReview'])->middleware('perm:finance.refunds');
            Route::post('refund-reviews', [BrandController::class, 'decideRefundReview'])->middleware('perm:finance.refunds');
        });

        // Siniflandirma listeleri
        Route::get('taxonomy/categories', [TaxonomyController::class, 'categories'])->middleware('perm:taxonomy.view');
        Route::post('taxonomy/categories', [TaxonomyController::class, 'createCategory'])->middleware('perm:taxonomy.manage');
        Route::patch('taxonomy/categories/{id}', [TaxonomyController::class, 'updateCategory'])->where('id', '[a-z][a-z0-9-]{1,63}')->middleware('perm:taxonomy.manage');
        Route::get('taxonomy/aliases', [TaxonomyController::class, 'aliases'])->middleware('perm:taxonomy.view');
        Route::put('taxonomy/aliases', [TaxonomyController::class, 'putAlias'])->middleware('perm:taxonomy.manage');
        Route::delete('taxonomy/aliases/{kind}/{term}', [TaxonomyController::class, 'deleteAlias'])->where(['kind' => 'alias|english', 'term' => '[^/]{2,120}'])->middleware('perm:taxonomy.manage');
        Route::get('taxonomy/cities', [TaxonomyController::class, 'cities'])->middleware('perm:taxonomy.view');
        Route::patch('taxonomy/cities/{name}', [TaxonomyController::class, 'updateCity'])->where('name', '[^/]{2,100}')->middleware('perm:taxonomy.manage');
        Route::get('taxonomy/export', [TaxonomyController::class, 'export'])->middleware('perm:taxonomy.view');

        // Operasyon
        Route::get('ops/scheduled-runs', [DashboardController::class, 'scheduledRuns'])->middleware('perm:ops.view');
        Route::get('ops/queues', [DashboardController::class, 'queues'])->middleware('perm:ops.view');
        Route::get('ops/failed-jobs', [DashboardController::class, 'failedJobs'])->middleware('perm:ops.view');
        Route::post('ops/failed-jobs/{uuid}/retry', [DashboardController::class, 'retryFailedJob'])->where('uuid', '[0-9a-f-]{36}')->middleware('perm:ops.manage');
        Route::post('ops/failed-jobs/{uuid}/forget', [DashboardController::class, 'forgetFailedJob'])->where('uuid', '[0-9a-f-]{36}')->middleware('perm:ops.manage');
    });
});
