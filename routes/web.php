<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WixController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WixAutomaticMigrationController;
use App\Http\Controllers\WixBrandController;
use App\Http\Controllers\WixCatalogController;
use App\Http\Controllers\WixCategoryController;
use App\Http\Controllers\WixContactController;
use App\Http\Controllers\WixCouponController;
use App\Http\Controllers\WixCustomizationController;
use App\Http\Controllers\WixDiscountRuleController;
use App\Http\Controllers\WixGiftCardController;
use App\Http\Controllers\WixInfoSectionController;
use App\Http\Controllers\WixInventoryController;
use App\Http\Controllers\WixLoyaltyAccountController;
use App\Http\Controllers\WixLoyaltyController;
use App\Http\Controllers\WixMediaController;
use App\Http\Controllers\WixMemberMigrationController;
use App\Http\Controllers\WixOrderController;
use App\Http\Controllers\WixProductController;
use App\Http\Controllers\WixRibbonController;
use App\Http\Controllers\WixStoreController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::get('/php-info', function () {
    phpinfo();
});
// Route::get('/dashboard', function () {
//     return view('dashboard');
// })->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    // Route::get('/wix', [WixController::class, 'dashboard'])->name('wix.dashboard');
    Route::get('/dashboard', [WixController::class, 'dashboard'])->name('wix.dashboard');
    // Route::get('/new-dashboard', [WixController::class, 'newDashboard'])->name('wix.new.dashboard');
    Route::get('/logs', [WixController::class, 'logs'])->name('wix.logs');

    Route::post('/stores/{store}', [WixStoreController::class, 'update'])->name('stores.update');
    Route::delete('/stores/{store}', [WixStoreController::class, 'destroy'])->name('stores.destroy');

    // ====================
    // Automated Migration Routes
    // ====================
    // Route::post('/wix/migrate', [WixAutomaticMigrationController::class, 'migrate'])->name('wix.migrate');

    
    // ====================
    // Manual Migration Routes
    // ====================

    // // Export merged
    // Route::get('/wix/{store}/export-catalog', [WixCatalogController::class, 'exportAll'])->name('wix.export.catalog');
    // Route::post('/wix/{store}/import-catalog', [WixCatalogController::class, 'importAll'])->name('wix.import.catalog');


    // // ==================== Product Items ====================
    // // Brands Routes
    // Route::get('/wix/{store}/export-brands', [WixBrandController::class, 'export'])->name('wix.export.brands');
    // Route::post('/wix/{store}/import-brands', [WixBrandController::class, 'import'])->name('wix.import.brands');
    // // Ribbons Routes
    // Route::get('/wix/{store}/export-ribbons', [WixRibbonController::class, 'export'])->name('wix.export.ribbons');
    // Route::post('/wix/{store}/import-ribbons', [WixRibbonController::class, 'import'])->name('wix.import.ribbons');
    // // Info sections Routes
    // Route::get('/wix/{store}/export-info-sections', [WixInfoSectionController::class, 'export'])->name('wix.export.info.sections');
    // Route::post('/wix/{store}/import-info-sections', [WixInfoSectionController::class, 'import'])->name('wix.import.info.sections');
    // // Customizations Routes
    // Route::get('/wix/{store}/export-customizations', [WixCustomizationController::class, 'export'])->name('wix.export.customizations');
    // Route::post('/wix/{store}/import-customizations', [WixCustomizationController::class, 'import'])->name('wix.import.customizations');
    // // ==================== Product Items ====================


    
    // Media Migration Routes
    Route::post('/wix/media/auto-migrate', [WixMediaController::class, 'migrateAuto'])->name('wix.migrate.media');
    Route::get('/wix/{store}/export-media', [WixMediaController::class, 'exportFolderWithFiles'])->name('wix.export.media');
    Route::post('/wix/{store}/import-media', [WixMediaController::class, 'importFolderWithFiles'])->name('wix.import.media');

    // Category Migration Routes
    Route::post('/wix/categories/auto-migrate', [WixCategoryController::class, 'migrateAuto'])->name('wix.migrate.categories');
    Route::get('/wix/{store}/export-categories', [WixCategoryController::class, 'export'])->name('wix.export.categories');
    Route::post('/wix/{store}/import-categories', [WixCategoryController::class, 'import'])->name('wix.import.categories');

    // Products Migration Routes
    Route::post('/wix/products/auto-migrate', [WixProductController::class, 'migrateAuto'])->name('wix.migrate.products');
    Route::get('/wix/{store}/export-products', [WixProductController::class, 'export'])->name('wix.export.products');
    Route::post('/wix/{store}/import-products', [WixProductController::class, 'import'])->name('wix.import.products');

    // Contacts Migration Routes
    Route::post('/wix/contacts/auto-migrate', [WixContactController::class, 'migrateAuto'])->name('wix.migrate.contacts.members');
    Route::get('/wix/{store}/export-contacts', [WixContactController::class, 'export'])->name('wix.export.contacts');
    Route::post('/wix/{store}/import-contacts', [WixContactController::class, 'import'])->name('wix.import.contacts');

    // Members Migration Routes
    Route::get('/wix/{store}/export-members', [WixMemberMigrationController::class, 'export'])->name('wix.export.members');
    Route::post('/wix/{store}/import-members', [WixMemberMigrationController::class, 'import'])->name('wix.import.members');

    // Orders Migration Routes
    Route::post('/wix/orders/auto-migrate', [WixOrderController::class, 'migrateAuto'])->name('wix.migrate.orders');
    Route::get('/wix/{store}/export-orders', [WixOrderController::class, 'export'])->name('wix.export.orders');
    Route::post('/wix/{store}/import-orders', [WixOrderController::class, 'import'])->name('wix.import.orders');

    // Discount Rules Migration Routes
    Route::post('/wix/discount-rules/auto-migrate', [WixDiscountRuleController::class, 'migrateAuto'])->name('wix.migrate.discount.rules');
    Route::get('/wix/{store}/export-discount-rules', [WixDiscountRuleController::class, 'export'])->name('wix.export.discount.rules');
    Route::post('/wix/{store}/import-discount-rules', [WixDiscountRuleController::class, 'import'])->name('wix.import.discount.rules');

    // Coupons Migration Routes
    Route::post('/wix/coupons/auto-migrate', [WixCouponController::class, 'migrateAuto'])->name('wix.migrate.coupons');
    Route::get('/wix/{store}/export-coupons', [WixCouponController::class, 'export'])->name('wix.export.coupons');
    Route::post('/wix/{store}/import-coupons', [WixCouponController::class, 'import'])->name('wix.import.coupons');
    
    // Gift Cards Migration Routes
    Route::post('/wix/gift-cards/auto-migrate', [WixGiftCardController::class, 'migrateAuto'])->name('wix.migrate.gift.cards');
    Route::get('/wix/{store}/export-gift-cards', [WixGiftCardController::class, 'export'])->name('wix.export.gift.cards');
    Route::post('/wix/{store}/import-gift-cards', [WixGiftCardController::class, 'import'])->name('wix.import.gift.cards');
    
    // Loyality Programs Migration Routes
    Route::post('/wix/loyalty/auto-migrate', [WixLoyaltyAccountController::class, 'migrateAuto'])->name('wix.migrate.loyalty');
    Route::get('/wix/{store}/loyalty-export', [WixLoyaltyAccountController::class, 'export'])->name('wix.loyalty.export');
    Route::post('/wix/{store}/loyalty-import', [WixLoyaltyAccountController::class, 'import'])->name('wix.loyalty.import');


});

