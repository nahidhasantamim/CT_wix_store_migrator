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
    'Contacts & Members' => ['key' => 'contacts_members_auto','migrate' => 'wix.migrate.contacts.members', 'label' => 'Contacts & Members'], 
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
    <form class="space-y-6" action="{{ route($cfg['migrate']) }}" method="POST">
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

      <div class="flex items-center justify-between">
        <button type="submit"
                class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5
                       dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">
          Start Migration
        </button>
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
<div class="mt-4 sm:mt-6">
  <div class="sticky bottom-2 sm:static">
    <div class="bg-gray-800/90 backdrop-blur rounded-lg p-3 sm:p-0 flex flex-col items-center sm:bg-transparent">
      <div class="mb-3 flex items-center sm:mb-4">
        <label for="confirm-{{ $idx }}" class="ml-2 text-sm text-gray-300">If you have already migrated <b class="text-blue-600">"{{ $title }}"</b>. Click on the checbox </label>
        &nbsp;
        <input id="confirm-{{ $idx }}" type="checkbox"
               class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500">
        {{-- <label for="confirm-{{ $idx }}" class="ml-2 text-sm text-gray-300">I confirm {{ $title }}</label> --}}
      </div>
      <div class="flex gap-2 sm:gap-3">
        <button type="button"
                class="btn-prev rounded-lg bg-gray-700 px-4 py-2 text-gray-200 hover:bg-gray-600 sm:px-6"
                {{ $idx == 1 ? 'disabled' : '' }}>
          Prev
        </button>
        <button type="button"
                class="btn-next hidden rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 sm:px-6">
          {{ $idx < 9 ? 'Next' : 'Finish' }}
        </button>
      </div>
    </div>
  </div>
</div>
