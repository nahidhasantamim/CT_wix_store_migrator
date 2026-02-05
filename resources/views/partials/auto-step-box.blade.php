@php
  // Map step titles to auto-migration route names
  $autoRouteMap = [
    'Categories'         => ['key' => 'categories_auto',      'migrate' => 'wix.migrate.categories',      'label' => 'Categories'],
    'Products'           => ['key' => 'products_auto',        'migrate' => 'wix.migrate.products',        'label' => 'Products'],
    'Orders'             => ['key' => 'orders_auto',          'migrate' => 'wix.migrate.orders',          'label' => 'Orders'],
    'Discounts'          => ['key' => 'discount_rules_auto',  'migrate' => 'wix.migrate.discount.rules',  'label' => 'Discount Rules'],
    'Coupons'            => ['key' => 'coupons_auto',         'migrate' => 'wix.migrate.coupons',         'label' => 'Coupons'],
    'Gift Cards'         => ['key' => 'gift_cards_auto',      'migrate' => 'wix.migrate.gift.cards',      'label' => 'Gift Cards'],
    'Loyalty'            => ['key' => 'loyalty_auto',         'migrate' => 'wix.migrate.loyalty',         'label' => 'Loyalty'],
    'Media'              => ['key' => 'media_auto',           'migrate' => 'wix.migrate.media',           'label' => 'Media'],
    'Contacts & Members' => ['key' => 'contacts_members_auto','migrate' => 'wix.migrate.contacts.members','label' => 'Contacts & Members'], 
    'Back in Stock'      => ['key' => 'back_in_stock_auto',   'migrate' => 'wix.migrate.back.in.stock',   'label' => 'Back in Stock'], 
  ];

  // Resolve current step config (and guard if missing)
  $cfg = $autoRouteMap[$title] ?? null;

  // Slug for module name (to match your manual hidden fields)
  $moduleSlug = \Illuminate\Support\Str::slug($title, '_');
@endphp

<div class="w-full bg-white border border-gray-200 shadow-sm dark:bg-gray-900 dark:border-gray-700 p-5 rounded-md">
  <div class="flex items-center justify-between mb-4">
    <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white">
      {{ $title }} - Automatic Migration
    </h3>
    @if(!$cfg)
      <span class="text-xs px-2 py-1 rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200">
        Route not configured for this step
      </span>
    @endif
  </div>

  @if($cfg)
    <form class="space-y-6" action="{{ route($cfg['migrate']) }}" method="POST" x-data="{ useRange: false }">
      @csrf
      {{-- Keep your meta fields consistent with manual flow --}}
      <input type="hidden" name="module_step" value="{{ $title }}">
      <input type="hidden" name="module" value="{{ $moduleSlug }}">

      <div class="flex gap-4 max-sm:flex-col">
        {{-- From Store --}}
        <div class="sm:w-1/2 w-full">
          <label for="auto_from_store_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            From Store
          </label>
          <select id="auto_from_store_{{ $idx }}" name="from_store" required
                  class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                         dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
            <option value="" selected disabled>Select Store</option>
            @foreach($stores as $s)
              <option value="{{ $s->instance_id }}">
                {{ $s->store_name }} ({{ \Illuminate\Support\Str::limit($s->instance_id, 30, '...') }})
              </option>
            @endforeach
          </select>
        </div>

        {{-- To Store --}}
        <div class="sm:w-1/2 w-full">
          <label for="auto_to_store_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            To Store
          </label>
          <select id="auto_to_store_{{ $idx }}" name="to_store" required
                  class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                         dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
            <option value="" selected disabled>Select Store</option>
            @foreach($stores as $s)
              <option value="{{ $s->instance_id }}">
                {{ $s->store_name }} ({{ \Illuminate\Support\Str::limit($s->instance_id, 30, '...') }})
              </option>
            @endforeach
          </select>
        </div>
      </div>

      {{-- Contacts-specific options --}}
      @if (($cfg['key'] ?? null) === 'contacts_members_auto')
        <div class="mt-2 space-y-3">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label for="auto_contacts_batch_pages_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                Batch pages (optional)
              </label>
              <input type="number" min="1" name="batch_pages" id="auto_contacts_batch_pages_{{ $idx }}"
                    class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                           dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    placeholder="e.g. 5">
            </div>
            <div>
              <label for="auto_contacts_resume_offset_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                Resume offset (optional)
              </label>
              <input type="number" min="0" name="resume_offset" id="auto_contacts_resume_offset_{{ $idx }}"
                    class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                           dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    placeholder="e.g. 10000">
            </div>
            <div>
              <label for="auto_contacts_page_limit_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                Page size (optional)
              </label>
              <input type="number" min="1" max="1000" name="page_limit" id="auto_contacts_page_limit_{{ $idx }}"
                    class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                           dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    placeholder="1000">
            </div>
          </div>
          <p class="text-[11px] text-gray-500 dark:text-gray-400">
            Use batch pages to run in smaller chunks. If it pauses, use the shown resume offset to continue.
          </p>
        </div>
      @endif

      {{-- Products-specific options --}}
      @if (($cfg['key'] ?? null) === 'products_auto')
        <!-- ================Specifi Product Migration=========== -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

        <div class="mt-2 space-y-2">
          <label for="auto_product_ids_{{ $idx }}" class="block mb-1 text-sm font-medium text-gray-900 dark:text-white">
            Select specific products (optional)
          </label>
          <input
            type="text"
            id="auto_product_search_{{ $idx }}"
            placeholder="Search products..."
            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                   dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
          >
          <select
            id="auto_product_ids_{{ $idx }}"
            name="product_ids[]"
            multiple
            data-products-url="{{ route('wix.products.byStore') }}"
            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 min-h-[140px]
                   dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
          >
            <option value="" disabled>Select From Store first</option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400">
            If you do not select any products, all products will be migrated as usual.
          </p>
        </div>

        <script>
          (function () {
            var fromSelect = document.getElementById('auto_from_store_{{ $idx }}');
            var productSelect = document.getElementById('auto_product_ids_{{ $idx }}');
            var searchInput = document.getElementById('auto_product_search_{{ $idx }}');
            if (!fromSelect || !productSelect) return;

            var endpoint = productSelect.getAttribute('data-products-url');
            if (!endpoint) return;

            function initSelect2() {
              if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(productSelect).select2({
                  width: '100%',
                  placeholder: 'Select products',
                  allowClear: true
                });
                if (searchInput) {
                  searchInput.style.display = 'none';
                }
              }
            }

            function resetOptions(message) {
              productSelect.innerHTML = '';
              var opt = document.createElement('option');
              opt.disabled = true;
              opt.value = '';
              opt.textContent = message;
              productSelect.appendChild(opt);
              if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(productSelect).trigger('change.select2');
              }
            }

            function setOptions(items) {
              productSelect.innerHTML = '';
              if (!items || !items.length) {
                resetOptions('No products found for this store');
                return;
              }
              items.forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name + ' (' + p.id + ')';
                productSelect.appendChild(opt);
              });
              if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(productSelect).trigger('change.select2');
              }
            }

            async function loadProducts() {
              var fromStore = fromSelect.value;
              if (!fromStore) {
                resetOptions('Select From Store first');
                return;
              }
              resetOptions('Loading products...');
              try {
                var url = endpoint + '?from_store=' + encodeURIComponent(fromStore);
                var resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
                var json = await resp.json();
                setOptions(json.products || []);
              } catch (e) {
                resetOptions('Failed to load products');
              }
            }

            fromSelect.addEventListener('change', function () {
              if (searchInput) searchInput.value = '';
              loadProducts();
            });

            initSelect2();

            if (searchInput) {
              searchInput.addEventListener('input', function () {
                var q = searchInput.value.toLowerCase().trim();
                Array.prototype.forEach.call(productSelect.options, function (opt) {
                  if (opt.disabled) return;
                  var text = (opt.textContent || '').toLowerCase();
                  opt.hidden = q !== '' && text.indexOf(q) === -1;
                });
              });
            }
          })();
        </script>
        <!-- ================Specifi Product Migration=========== -->
      @endif

      {{-- Orders-specific options --}}
      @if (($cfg['key'] ?? null) === 'orders_auto')
        <div class="mt-2 space-y-4">
          {{-- Hard limit --}}
          <div class="sm:w-1/3 w-full">
            <label for="auto_limit_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
              Max orders (optional)
            </label>
            <input type="number" min="1" name="limit" id="auto_limit_{{ $idx }}"
                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                          dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                   placeholder="e.g. 200">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hard cap per run. Leave empty for no cap.</p>
          </div>

          {{-- Date range filter --}}
          <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="use_date_range" value="1"
                   x-model="useRange"
                   class="h-4 w-4 rounded border-gray-300 bg-gray-50 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
            Filter by order creation date
          </label>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"
               :class="{ 'opacity-100': useRange, 'opacity-60': !useRange }">
            <div>
              <label for="auto_date_from_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                Start date
              </label>
              <input type="date" name="created_from" id="auto_date_from_{{ $idx }}"
                     :disabled="!useRange"
                     class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                            dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            </div>
            <div>
              <label for="auto_date_to_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                End date
              </label>
              <input type="date" name="created_to" id="auto_date_to_{{ $idx }}"
                     :disabled="!useRange"
                     class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                            dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            </div>
          </div>

          <p class="text-[11px] text-gray-500 dark:text-gray-400">
            Dates are interpreted in <span class="font-medium text-gray-800 dark:text-gray-200">Pacific Time (PT)</span>,
            inclusive start/end of day.
          </p>
        </div>
      @endif

      {{-- Gift Cards-specific options --}}
      @if (($cfg['key'] ?? null) === 'gift_cards_auto')
        <div class="mt-2 space-y-4">

          {{-- Hard limit --}}
          <div class="sm:w-1/3 w-full">
            <label for="auto_gc_limit_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
              Max gift cards (optional)
            </label>
            <input type="number" min="1" name="limit" id="auto_gc_limit_{{ $idx }}"
                  class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5
                          dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white"
                  placeholder="e.g. 200">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hard cap per run. Leave empty for no cap.</p>
          </div>

          {{-- Date Range Enable --}}
          <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="use_date_range" value="1"
                  x-model="useRange"
                  class="h-4 w-4 rounded border-gray-300 bg-gray-50 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
            Filter by gift card creation date
          </label>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"
              :class="{ 'opacity-100': useRange, 'opacity-60': !useRange }">
            <div>
              <label for="auto_gc_date_from_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                Start date
              </label>
              <input type="date" name="from_date" id="auto_gc_date_from_{{ $idx }}"
                    :disabled="!useRange"
                    class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                            dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            </div>

            <div>
              <label for="auto_gc_date_to_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
                End date
              </label>
              <input type="date" name="to_date" id="auto_gc_date_to_{{ $idx }}"
                    :disabled="!useRange"
                    class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                            dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            </div>
          </div>

          <p class="text-[11px] text-gray-500 dark:text-gray-400">
            Dates are interpreted in <span class="font-medium text-gray-800 dark:text-gray-200">Pacific Time (PT)</span>,
            inclusive start/end of day.
          </p>

        </div>
      @endif

      <div class="flex items-center justify-between pt-2">
        <div class="btn-group">
          <button type="submit"
                class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5
                       dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">
            Start Migration
          </button>
          @if (($cfg['key'] ?? null) === 'contacts_members_auto')
          <button
              type="submit"
              name="missing_only"
              value="1"
              class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5
                     dark:bg-green-600 dark:hover:bg-green-700 dark:focus:ring-green-800"
          >
              Migrate Missing Only
          </button>
          @endif
          @if (($cfg['key'] ?? null) === 'categories_auto')
          <button
              type="submit"
              formaction="{{ route('wix.categories.syncSeo') }}"
              class="text-white bg-yellow-700 hover:bg-yellow-800 focus:ring-4 focus:outline-none focus:ring-yellow-300 font-medium rounded-lg text-sm px-5 py-2.5
                       dark:bg-yellow-600 dark:hover:bg-yellow-700 dark:focus:ring-yellow-800"
          >
              Sync SEO Data
          </button>
          @endif
          @if (($cfg['key'] ?? null) === 'products_auto')
          <button
              type="submit"
              formaction="{{ route('wix.products.syncSeo') }}"
              class="text-white bg-yellow-700 hover:bg-yellow-800 focus:ring-4 focus:outline-none focus:ring-yellow-300 font-medium rounded-lg text-sm px-5 py-2.5
                       dark:bg-yellow-600 dark:hover:bg-yellow-700 dark:focus:ring-yellow-800"
          >
              Sync SEO Data
          </button>
          @endif
        </div>
        
        <p class="text-xs text-gray-500 dark:text-gray-400">
          <b class="text-blue-600">{{ $cfg['label'] }}</b> - Data will be automatically migrated from "FROM STORE" to "TO STORE"
        </p>
      </div>
    </form>
  @else
    <div class="p-4 rounded border border-yellow-200 bg-yellow-50 text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-900/40 dark:text-yellow-100">
      Please configure a migrate route for <strong>{{ $title }}</strong> in <code>web.php</code>, then add it to <code>$autoRouteMap</code>.
    </div>
  @endif
</div>

{{-- Footer (confirmation checkbox gates Next/Finish) --}}
<div class="mt-4 sm:mt-6" x-data="{ confirmed: false }">
  <div class="sticky bottom-2 sm:static">
    <div class="bg-gray-800/90 backdrop-blur rounded-lg p-3 sm:p-0 flex flex-col items-center sm:bg-transparent">
      <div class="mb-3 flex items-center sm:mb-4">
        <label for="confirm-{{ $idx }}" class="ml-2 text-sm text-gray-300">
          If you have already migrated <b class="text-blue-600">"{{ $title }}"</b>, click the checkbox
        </label>
        &nbsp;
        <input id="confirm-{{ $idx }}" type="checkbox"
               class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500"
               x-model="confirmed">
      </div>

      <div class="flex gap-2 sm:gap-3">
        <button type="button"
                class="btn-prev rounded-lg bg-gray-700 px-4 py-2 text-gray-200 hover:bg-gray-600 sm:px-6"
                {{ $idx == 1 ? 'disabled' : '' }}>
          Prev
        </button>

        <button type="button"
                class="btn-next rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 sm:px-6"
                :class="{ 'hidden': !confirmed }"
                :disabled="!confirmed"
                aria-disabled="true">
          {{ $idx < 10 ? 'Next' : 'Finish' }}
        </button>
      </div>
    </div>
  </div>
</div>
