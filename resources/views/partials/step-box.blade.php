@php
  // Map step titles to route names + file input keys
  $routeMap = [
    'Categories' => ['key' => 'categories', 'export' => 'wix.export.categories', 'import' => 'wix.import.categories', 'label' => 'Categories'],
    'Products' => ['key' => 'products', 'export' => 'wix.export.products', 'import' => 'wix.import.products', 'label' => 'Products'],
    'Orders' => ['key' => 'orders', 'export' => 'wix.export.orders', 'import' => 'wix.import.orders', 'label' => 'Orders'],
    'Discounts' => ['key' => 'discount_rules', 'export' => 'wix.export.discount.rules', 'import' => 'wix.import.discount.rules', 'label' => 'Discount Rules'],
    'Coupons' => ['key' => 'coupons', 'export' => 'wix.export.coupons', 'import' => 'wix.import.coupons', 'label' => 'Coupons'],
    'Gift Cards' => ['key' => 'gift_cards', 'export' => 'wix.export.gift.cards', 'import' => 'wix.import.gift.cards', 'label' => 'Gift Cards'],
    'Loyalty' => ['key' => 'loyalty', 'export' => 'wix.loyalty.export', 'import' => 'wix.loyalty.import', 'label' => 'Loyalty'],
    'Media' => ['key' => 'media', 'export' => 'wix.export.media', 'import' => 'wix.import.media', 'label' => 'Media'],
    'Contacts & Members' => ['key' => 'contacts', 'export' => 'wix.export.contacts', 'import' => 'wix.import.contacts', 'label' => 'Contacts & Members'],
    'Back in Stock' => ['key' => 'back_in_stock', 'export' => 'wix.export.back.in.stock', 'import' => 'wix.import.back.in.stock', 'label' => 'Back in Stock'],
  ];
  // $isContactsMembers = ($title === 'Contacts & Members');
@endphp

<div class="bg-gray-800 text-white rounded-lg shadow p-5 sm:p-6">
  <h2 class="text-lg sm:text-xl font-semibold mb-1">Step {{ $idx }} - {{ $title }}</h2>
  <p class="text-xs sm:text-sm text-gray-400 mb-4">Find all your connected stores below.</p>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
    @foreach ($stores as $store)
      <div class="relative rounded-lg border border-gray-700 bg-gray-900 p-4 sm:p-5 shadow min-h-[14rem] flex flex-col">
        {{-- Kebab / actions (IDs unique per step+store) --}}
        <div class="absolute right-2 top-2">
          <button
            id="dropdownButton-{{ $idx }}-{{ $store->id }}"
            data-dropdown-toggle="dropdown-{{ $idx }}-{{ $store->id }}"
            data-dropdown-trigger="click"
            data-dropdown-placement="bottom-end"
            type="button"
            class="inline-flex items-center justify-center rounded-lg p-1.5 text-gray-400 hover:bg-gray-800 hover:text-white focus:outline-none focus:ring-2 focus:ring-blue-600/40"
          >
            <span class="sr-only">Open menu</span>
            <svg class="w-5 h-5" viewBox="0 0 16 3" fill="currentColor" aria-hidden="true">
              <path d="M2 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6.041 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM14 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>
            </svg>
          </button>

          <div id="dropdown-{{ $idx }}-{{ $store->id }}"
               class="z-30 hidden w-44 rounded-lg border border-gray-200/10 bg-gray-50 text-base shadow-sm dark:bg-gray-800">
            <ul class="py-2 text-sm text-gray-700 dark:text-gray-200" aria-labelledby="dropdownButton-{{ $idx }}-{{ $store->id }}">
              <li>
                <a href="#"
                   data-modal-target="rename-modal-{{ $idx }}-{{ $store->id }}"
                   data-modal-toggle="rename-modal-{{ $idx }}-{{ $store->id }}"
                   class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 dark:hover:text-white">
                  Rename Store
                </a>
              </li>
              <li>
                <button type="button"
                        class="block w-full text-left px-4 py-2 text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-700"
                        x-data
                        x-on:click.prevent="$dispatch('open-modal','confirm-store-deletion-{{ $idx }}-{{ $store->id }}')">
                  Delete
                </button>
              </li>
            </ul>
          </div>
        </div>

        {{-- Card body --}}
        <div class="flex flex-col items-center mt-4 sm:mt-6">
          @if ($store->store_logo)
            <img class="w-24 h-24 mb-3 rounded-full shadow-lg border-4 border-dashed border-blue-800 object-cover"
                 src="{{ asset('storage/'.$store->store_logo) }}" alt="{{ $store->store_name ?? 'Store Logo' }}">
          @else
            <img class="w-24 h-24 mb-3 rounded-full shadow-lg"
                 src="https://img.icons8.com/external-tal-revivo-color-tal-revivo/96/external-wixcom-ltd-is-an-israeli-cloud-based-web-development-logo-color-tal-revivo.png"
                 alt="{{ $store->store_name ?? 'Wix Store Logo' }}">
          @endif

          <h5 class="mb-1 text-lg sm:text-xl font-semibold text-white text-center">{{ $store->store_name }}</h5>
          <span class="text-[11px] sm:text-xs text-gray-400 text-center">
            Instance ID: ({{ \Illuminate\Support\Str::limit($store->instance_id, 30, '…') }})
          </span>
        </div>

        {{-- Actions --}}
        <div class="mt-4 grid w-full gap-3">

            @php $conf = $routeMap[$title] ?? null; @endphp
            @if ($conf)
              {{-- Export --}}
              <div class="rounded-lg border border-gray-700 bg-gray-800 p-3 sm:p-4">
                <h3 class="text-sm font-semibold text-white mb-2 text-center">Export {{ $conf['label'] }}</h3>

                @if (($conf['key'] ?? null) === 'orders')
                  {{-- Orders export: optional date range filter --}}
                  {{-- Orders export: optional date range filter + limit --}}
                  <form action="{{ route($conf['export'], $store) }}" method="GET" class="space-y-3" x-data="{ useRange: false }">
                    {{-- Enable date range filter --}}
                    <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                      <input type="checkbox" name="use_date_range" value="1"
                            x-model="useRange"
                            class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500">
                      Filter by order creation date
                    </label>

                    {{-- Date inputs --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2"
                        :class="{ 'opacity-100': useRange, 'opacity-60': !useRange }">
                      <div>
                        <label class="block text-xs text-gray-300 mb-1" for="date_from_{{ $idx }}_{{ $store->id }}">Start date</label>
                        <input type="date" name="created_from" id="date_from_{{ $idx }}_{{ $store->id }}"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              :disabled="!useRange">
                      </div>
                      <div>
                        <label class="block text-xs text-gray-300 mb-1" for="date_to_{{ $idx }}_{{ $store->id }}">End date</label>
                        <input type="date" name="created_to" id="date_to_{{ $idx }}_{{ $store->id }}"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              :disabled="!useRange">
                      </div>
                    </div>

                    {{-- Hard cap limit --}}
                    <div>
                      <label class="block text-xs text-gray-300 mb-1" for="limit_{{ $idx }}_{{ $store->id }}">Max orders (optional)</label>
                      <input type="number" min="1" name="limit" id="limit_{{ $idx }}_{{ $store->id }}"
                            class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. 200">
                    </div>

                    {{-- Start from order number --}}
                    <div>
                      <label class="block text-xs text-gray-300 mb-1" for="start_order_number_{{ $idx }}_{{ $store->id }}">
                        Start after Order #
                      </label>
                      <input type="text" name="start_order_number" id="start_order_number_{{ $idx }}_{{ $store->id }}"
                            class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. 105200">
                      <p class="text-[11px] text-gray-400 mt-1">
                        Fetches orders after this number (useful for next batch exports).
                      </p>
                    </div>

                    <p class="text-[11px] text-gray-400">
                      Dates are interpreted in <span class="font-medium text-gray-200">Pacific Time (PT)</span>, inclusive start/end of day.
                    </p>

                    <button type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60">
                      Download
                    </button>
                  </form>


                @elseif (($conf['key'] ?? null) === 'contacts')
                  {{-- Contacts & Members export: with optional PT date filter + options --}}
                  <form action="{{ route($conf['export'], $store) }}" method="GET" class="space-y-3" x-data="{ useRange: false }">
                    {{-- Optional: cap results --}}
                    <div>
                      <label class="block text-xs text-gray-300 mb-1" for="max_{{ $idx }}_{{ $store->id }}">Max (optional)</label>
                      <input type="number" min="1" name="max" id="max_{{ $idx }}_{{ $store->id }}"
                            class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. 500">
                    </div>

                    {{-- Include toggles --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                        <input type="checkbox" name="include_members" value="1" class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500" checked>
                        Include Members
                      </label>
                      <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                        <input type="checkbox" name="include_attachments" value="1" class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500" checked>
                        Include Attachments
                      </label>
                    </div>

                    <div class="border-t border-gray-700 pt-3"></div>

                    {{-- Enable date range filter --}}
                    <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                      <input type="checkbox" name="use_date_range" value="1"
                            x-model="useRange"
                            class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500">
                      Filter by date range (Pacific Time)
                    </label>

                    {{-- Date field select + inputs --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2"
                        :class="{ 'opacity-100': useRange, 'opacity-60': !useRange }">
                      <div>
                        <label class="block text-xs text-gray-300 mb-1" for="date_field_{{ $idx }}_{{ $store->id }}">Date Field</label>
                        <select name="date_field" id="date_field_{{ $idx }}_{{ $store->id }}"
                                class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                                :disabled="!useRange">
                          <option value="created" selected>Created</option>
                          <option value="updated">Updated</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-xs text-gray-300 mb-1" for="start_date_{{ $idx }}_{{ $store->id }}">Start date</label>
                        <input type="date" name="start_date" id="start_date_{{ $idx }}_{{ $store->id }}"
                              placeholder="YYYY-MM-DD or DD.MM.YYYY"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              :disabled="!useRange">
                      </div>
                      <div>
                        <label class="block text-xs text-gray-300 mb-1" for="end_date_{{ $idx }}_{{ $store->id }}">End date</label>
                        <input type="date" name="end_date" id="end_date_{{ $idx }}_{{ $store->id }}"
                              placeholder="YYYY-MM-DD or DD.MM.YYYY"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              :disabled="!useRange">
                      </div>
                    </div>

                    <p class="text-[11px] text-gray-400">
                      Dates are interpreted in <span class="font-medium text-gray-200">America/Los_Angeles</span> (inclusive start/end of day).
                      Accepts <code>YYYY-MM-DD</code> or <code>DD.MM.YYYY</code>.
                    </p>

                    <button type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60">
                      Download
                    </button>
                  </form>

                @elseif (($conf['key'] ?? null) === 'gift_cards')
                  {{-- Gift Cards export: optional date range + limit --}}
                  <form action="{{ route($conf['export'], $store) }}" method="GET" class="space-y-3" x-data="{ useRange: false }">

                    {{-- Enable date range --}}
                    <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                      <input type="checkbox" name="use_date_range" value="1"
                            x-model="useRange"
                            class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500">
                      Filter by creation date
                    </label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2"
                        :class="{ 'opacity-100': useRange, 'opacity-60': !useRange }">
                      <div>
                        <label class="block text-xs text-gray-300 mb-1">Start Date</label>
                        <input type="date" name="from_date"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100"
                              :disabled="!useRange">
                      </div>

                      <div>
                        <label class="block text-xs text-gray-300 mb-1">End Date</label>
                        <input type="date" name="to_date"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100"
                              :disabled="!useRange">
                      </div>
                    </div>

                    {{-- Limit --}}
                    <div>
                      <label class="block text-xs text-gray-300 mb-1">Max Gift Cards (optional)</label>
                      <input type="number" min="1" max="100" name="limit"
                            class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100"
                            placeholder="e.g. 100">
                    </div>

                    <button type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500/60">
                      Download
                    </button>
                  </form>

                @elseif (($conf['key'] ?? null) === 'back_in_stock')
                  {{-- Back in Stock export: sort direction + optional limit --}}
                  <form action="{{ route($conf['export'], $store) }}" method="GET" class="space-y-3">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <div>
                        <label class="block text-xs text-gray-300 mb-1">Select By Created Date</label>
                        <select name="sort_order"
                                class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500">
                          <option value="DESC">Latest first</option>
                          <option value="ASC">Oldest first</option>
                        </select>
                      </div>

                      <div>
                        <label class="block text-xs text-gray-300 mb-1">Max Rows (optional)</label>
                        <input type="number" min="1" name="limit"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              placeholder="e.g. 100">
                      </div>
                    </div>

                    <p class="text-xs text-gray-400">
                      Output file is always sorted oldest &rarr; latest so the import replays chronologically.
                      <b>Latest first</b> + limit = most recent N requests; <b>Oldest first</b> + limit = earliest N.
                    </p>

                    <button type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:ring-2 focus:ring-blue-500/60">
                      Download
                    </button>
                  </form>

                @else
                  {{-- Default export for all other entities --}}
                  <a href="{{ route($conf['export'], $store) }}"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60">
                    Download
                  </a>
                @endif
              </div>

              {{-- Import --}}
              {{-- <div class="rounded-lg border border-gray-700 bg-gray-800 p-3 sm:p-4">
                <h3 class="text-sm font-semibold text-white mb-2 text-center">Import {{ $conf['label'] }}</h3>
                <form action="{{ route($conf['import'], $store) }}" method="POST" enctype="multipart/form-data" class="w-full">
                  @csrf
                  <div class="relative">
                    <input type="file"
                           name="{{ $conf['key'] }}_json"
                           id="{{ $conf['key'] }}_json_{{ $idx }}_{{ $store->id }}"
                           accept=".json"
                           class="block w-full cursor-pointer rounded-lg border border-gray-600 bg-gray-900 text-sm text-gray-200 file:mr-2 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-gray-700 file:px-3 file:py-2 file:text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                           required>
                    <button type="submit"
                            class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60 sm:absolute sm:top-0 sm:right-0 sm:mt-0 sm:h-full sm:w-auto sm:rounded-l-none">
                      Upload
                    </button>
                  </div>
                </form>
              </div> --}}

              {{-- Import --}}
              <div class="rounded-lg border border-gray-700 bg-gray-800 p-3 sm:p-4">
                <h3 class="text-sm font-semibold text-white mb-2 text-center">Import {{ $conf['label'] }}</h3>

                <form action="{{ route($conf['import'], $store) }}" method="POST" enctype="multipart/form-data" class="w-full">
                  @csrf

                  <div class="relative">
                    <input type="file"
                          name="{{ $conf['key'] }}_json"
                          id="{{ $conf['key'] }}_json_{{ $idx }}_{{ $store->id }}"
                          accept=".json"
                          class="block w-full cursor-pointer rounded-lg border border-gray-600 bg-gray-900 text-sm text-gray-200
                                  file:mr-2 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-gray-700
                                  file:px-3 file:py-2 file:text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                          required>

                    {{-- Submit Button --}}
                    <button type="submit"
                            class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white
                                  hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60 sm:absolute sm:top-0 sm:right-0
                                  sm:mt-0 sm:h-full sm:w-auto sm:rounded-l-none">
                      Upload
                    </button>
                  </div>

                  {{-- ✔ ONLY show for Gift Cards --}}
                  @if($conf['key'] === 'gift_cards')
                    <div class="mt-3 flex items-center space-x-2 bg-gray-900 border border-gray-700 rounded-lg p-3">
                      <input type="checkbox"
                            name="send_notification"
                            id="send_notification_{{ $idx }}_{{ $store->id }}"
                            value="1"
                            class="h-4 w-4 rounded border-gray-600 bg-gray-800 text-blue-600 focus:ring-blue-500">

                      <label for="send_notification_{{ $idx }}_{{ $store->id }}"
                            class="text-sm text-gray-300 cursor-pointer">
                        Send email notification to recipient?
                      </label>
                    </div>
                  @endif

                </form>
              </div>

              {{-- Back in Stock: Delete by uploaded JSON --}}
              @if (($conf['key'] ?? null) === 'back_in_stock')
                <div class="rounded-lg border border-red-700 bg-red-900/20 p-3 sm:p-4 mt-2">
                  <h3 class="text-sm font-semibold text-white mb-2 text-center">Delete Back in Stock (from JSON)</h3>

                  <form action="{{ route('wix.delete.back.in.stock', $store) }}" method="POST"
                        enctype="multipart/form-data" class="w-full"
                        onsubmit="return confirm('This will PERMANENTLY delete every Back-in-Stock request in the uploaded JSON from this store. Continue?');">
                    @csrf

                    <div class="relative">
                      <input type="file"
                            name="back_in_stock_json"
                            id="back_in_stock_delete_json_{{ $idx }}_{{ $store->id }}"
                            accept=".json"
                            class="block w-full cursor-pointer rounded-lg border border-gray-600 bg-gray-900 text-sm text-gray-200
                                    file:mr-2 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-gray-700
                                    file:px-3 file:py-2 file:text-gray-100 focus:border-red-500 focus:ring-red-500"
                            required>

                      <button type="submit"
                              class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white
                                    hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/60 sm:absolute sm:top-0 sm:right-0
                                    sm:mt-0 sm:h-full sm:w-auto sm:rounded-l-none">
                        Delete
                      </button>
                    </div>

                    <p class="mt-2 text-xs text-red-300">
                      Upload the same JSON that was downloaded via the <b>Export Back in Stock</b> button. Every request whose <code>id</code> exists on this store will be deleted.
                    </p>
                  </form>
                </div>
              @endif

              @if (($conf['key'] ?? null) === 'contacts')
                <!-- NEW SYNC BUTTONS -->
                <div class="grid">


                  <!-- Destination Store Sync -->
                  <form action="{{ route('wix.contacts.syncDestination', $store) }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="w-full inline-flex items-center justify-center text-gray-900 bg-white border border-gray-300 focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-100 font-medium rounded-full text-sm py-2.5 dark:bg-gray-800 dark:text-white dark:border-gray-600 dark:hover:bg-gray-700 dark:hover:border-gray-600 dark:focus:ring-gray-700 mt-2">
                      Sync Skipped Destination Contacts
                    </button>
                  </form>
                  <!-- END NEW SYNC BUTTONS -->

                  <form action="{{ route('wix.contacts.cleanup.orphans', $store->id) }}" method="POST">
                      @csrf
                      <button type="submit" class="inline-flex w-full items-center justify-center bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/60 rounded-full mt-2">
                          Clean Duplicate Contacts in Destination Store
                      </button>
                  </form>

                  <!-- Source Store Sync -->
                  <form action="{{ route('wix.contacts.compare.export', $store->id) }}" method="POST">
                      @csrf
                      <button type="submit"
                            class="w-full inline-flex items-center justify-center text-gray-900 bg-white border border-gray-300 focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-100 font-medium rounded-full text-sm py-2.5 dark:bg-gray-800 dark:text-white dark:border-gray-600 dark:hover:bg-gray-700 dark:hover:border-gray-600 dark:focus:ring-gray-700 mt-2">
                          Compare & Export Missing Contacts
                      </button>
                  </form>
                </div>
              @endif
            @else
              <div class="rounded-lg border border-yellow-700 bg-yellow-900/30 p-4 text-yellow-200">
                Route mapping for “{{ $title }}” not found.
              </div>
            @endif
          {{-- @endif --}}
        </div>
      </div>

      {{-- Rename / Update modal --}}
      <div id="rename-modal-{{ $idx }}-{{ $store->id }}" tabindex="-1" aria-hidden="true"
           class="fixed inset-0 z-50 hidden h-[100dvh] w-full items-center justify-center overflow-y-auto overflow-x-hidden p-4">
        <div class="relative w-full max-w-md">
          <div class="relative rounded-lg bg-white shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-600">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Update Store Info</h3>
              <button type="button" data-modal-hide="rename-modal-{{ $idx }}-{{ $store->id }}"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-200 hover:text-gray-900 focus:outline-none dark:hover:bg-gray-700 dark:hover:text-white">
                <span class="sr-only">Close</span>
                <svg class="h-3 w-3" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <div class="p-4">
              <form action="{{ route('stores.update', $store) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('POST')
                <div>
                  <label for="store_name_{{ $idx }}_{{ $store->id }}" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">Store Name</label>
                  <input type="text" id="store_name_{{ $idx }}_{{ $store->id }}" name="store_name"
                         value="{{ $store->store_name }}"
                         class="block w-full rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white" required>
                </div>
                <div>
                  <label for="store_logo_{{ $idx }}_{{ $store->id }}" class="mb-2 block text-sm font-medium text-gray-900 dark:text-white">Store Logo</label>
                  <input type="file" id="store_logo_{{ $idx }}_{{ $store->id }}" name="store_logo" accept="image/*"
                         class="block w-full cursor-pointer rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-blue-600 px-5 py-2.5 text-center text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60">
                  Update
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>

      {{-- Delete modal (Jetstream-style component, unique name) --}}
      <x-modal name="confirm-store-deletion-{{ $idx }}-{{ $store->id }}" :show="false" focusable>
        <form method="POST" action="{{ route('stores.destroy', $store->id) }}" class="p-6">
          @csrf
          @method('DELETE')
          <h2 class="text-lg font-medium text-gray-900 dark:text-white">Delete Store</h2>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            Once your store is deleted, all associated logs and data will be permanently removed. This action cannot be undone.
          </p>
          <div class="mt-6 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">Cancel</x-secondary-button>
            <x-danger-button class="ms-3">Delete Store</x-danger-button>
          </div>
        </form>
      </x-modal>
    @endforeach
  </div>

  @if ($title === 'Contacts & Members')
    <div class="w-full bg-white border border-gray-200 shadow-sm dark:bg-gray-900 dark:border-gray-700 p-5 rounded-md mt-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white">
          Compare Between Stores (Export Missing)
        </h3>
      </div>

      <form action="{{ route('wix.contacts.export.missing.between') }}" method="POST" class="space-y-6">
        @csrf

        <div class="flex gap-4 max-sm:flex-col">
          <div class="sm:w-1/2 w-full">
            <label for="compare_from_store_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
              From Store
            </label>
            <select id="compare_from_store_{{ $idx }}" name="from_store" required
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

          <div class="sm:w-1/2 w-full">
            <label for="compare_to_store_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
              To Store
            </label>
            <select id="compare_to_store_{{ $idx }}" name="to_store" required
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

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label for="compare_max_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
              Max (optional)
            </label>
            <input type="number" min="1" name="max" id="compare_max_{{ $idx }}"
                  class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                         dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder="e.g. 500">
          </div>
          <div>
            <label for="compare_page_limit_{{ $idx }}" class="block mb-1 text-xs font-medium text-gray-900 dark:text-gray-300">
              Page Size
            </label>
            <input type="number" min="1" max="1000" name="page_limit" id="compare_page_limit_{{ $idx }}"
                  class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500
                         dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder="1000">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="inline-flex items-center gap-2 text-sm text-gray-900 dark:text-gray-200">
            <input type="checkbox" name="include_members" value="1"
                  class="h-4 w-4 rounded border-gray-300 bg-gray-50 text-blue-600 focus:ring-blue-500
                         dark:bg-gray-700 dark:border-gray-600" checked>
            Include Members
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-gray-900 dark:text-gray-200">
            <input type="checkbox" name="include_attachments" value="1"
                  class="h-4 w-4 rounded border-gray-300 bg-gray-50 text-blue-600 focus:ring-blue-500
                         dark:bg-gray-700 dark:border-gray-600" checked>
            Include Attachments
          </label>
        </div>

        <button type="submit"
                class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60">
          Export Missing Contacts
        </button>
      </form>
    </div>

    <div class="w-full bg-white border border-gray-200 shadow-sm dark:bg-gray-900 dark:border-gray-700 p-5 rounded-md mt-4">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white">
          Delete Contacts From JSON
        </h3>
      </div>

      <form action="{{ route('wix.contacts.delete.by.json') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
        @csrf

        <div>
          <label for="delete_to_store_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            Target Store (delete from)
          </label>
          <select id="delete_to_store_{{ $idx }}" name="to_store" required
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

        <div>
          <label for="delete_contacts_json_{{ $idx }}" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">
            Contacts JSON File
          </label>
          <input type="file"
                 name="contacts_json"
                 id="delete_contacts_json_{{ $idx }}"
                 accept=".json"
                 class="block w-full cursor-pointer rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900
                        file:mr-2 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-gray-200
                        file:px-3 file:py-2 file:text-gray-900 focus:border-blue-500 focus:ring-blue-500
                        dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:file:bg-gray-600">
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Use the exported JSON from “Compare Between Stores”.
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="inline-flex items-center gap-2 text-sm text-gray-900 dark:text-gray-200">
            <input type="checkbox" name="dry_run" value="1"
                   class="h-4 w-4 rounded border-gray-300 bg-gray-50 text-blue-600 focus:ring-blue-500
                          dark:bg-gray-700 dark:border-gray-600">
            Dry run only (no deletes)
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-gray-900 dark:text-gray-200">
            <input type="checkbox" name="confirm" value="1"
                   class="h-4 w-4 rounded border-gray-300 bg-gray-50 text-blue-600 focus:ring-blue-500
                          dark:bg-gray-700 dark:border-gray-600" required>
            I understand this will delete contacts
          </label>
        </div>

        <button type="submit"
                class="inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/60">
          Delete Contacts
        </button>
      </form>
    </div>
  @endif
</div>

@php
  $manualNotes = [
    'Categories' => [
      'title' => 'Categories - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of all categories from the selected store (supports both Wix V1 and V3 APIs).',
        '<strong>Import:</strong> Uploads the JSON to the target store - updates existing categories matched by slug or name, creates missing ones.',
        'Preserves: name, slug, description, cover image, and visibility.',
        'The system "All Products" category is updated only - never deleted or recreated.',
        'Duplicate slugs are handled automatically by generating unique variants.',
      ],
    ],
    'Products' => [
      'title' => 'Products - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of all products with full variant and inventory data from the selected store.',
        '<strong>Import:</strong> Uploads the JSON to the target store - updates existing products, creates missing ones.',
        'Migrates product fields: name, slug, description, type (physical / digital), SKU, price, weight, and stock.',
        'Includes product variants with individual inventory quantities and in-stock status.',
        'Preserves product media (images), options, ribbons, brands, info sections, and custom text fields.',
      ],
    ],
    'Contacts & Members' => [
      'title' => 'Contacts & Members - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of contacts (with optional date filter and max cap).',
        '<strong>Import:</strong> Uploads the JSON to create or update contacts in the target store.',
        'Preserves: name, email, phone, labels, addresses, and custom fields.',
        '<strong>Compare & Export Missing:</strong> Finds contacts present in the source but missing in the target, then exports them.',
        '<strong>Delete Contacts from JSON:</strong> Removes a list of contacts from a target store using an exported JSON file.',
      ],
    ],
    'Orders' => [
      'title' => 'Orders - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of orders with optional date range filter and order cap.',
        '<strong>Import:</strong> Uploads the JSON to re-create orders in the target store.',
        'Preserves order details: line items, buyer info, payment status, shipping, and totals.',
      ],
    ],
    'Discounts' => [
      'title' => 'Discounts - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of all discount rules from the selected store.',
        '<strong>Import:</strong> Uploads the JSON to create or update discount rules in the target store.',
        'Preserves: name, scope, discount type, amount / percentage, and active status.',
      ],
    ],
    'Coupons' => [
      'title' => 'Coupons - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of all coupons from the selected store.',
        '<strong>Import:</strong> Uploads the JSON to create or update coupons in the target store, matched by code.',
        'Preserves: coupon code, discount type, value, usage limits, and expiry date.',
      ],
    ],
    'Gift Cards' => [
      'title' => 'Gift Cards - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of gift cards with optional date filter and cap.',
        '<strong>Import:</strong> Uploads the JSON to create gift cards in the target store.',
        'Option to send an email notification to the recipient on import.',
        'Preserves: initial value, currency, and expiry date.',
        '<strong>Note:</strong> Wix does not allow setting the current balance via API — each migrated card starts at its original face value (initialValue), not the remaining balance.',
      ],
    ],
    'Loyalty' => [
      'title' => 'Loyalty - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of loyalty accounts from the selected store.',
        '<strong>Import:</strong> Uploads the JSON to create or update loyalty accounts in the target store.',
        'Preserves: points balance, tier, and account status.',
      ],
    ],
    'Media' => [
      'title' => 'Media - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file listing all media items from the selected store.',
        '<strong>Import:</strong> Uploads media files to the target store.',
        'Preserves: file name, alt text, and folder structure.',
      ],
    ],
    'Back in Stock' => [
      'title' => 'Back in Stock - Manual Migration Capabilities',
      'points' => [
        '<strong>Export:</strong> Downloads a JSON file of back-in-stock notification subscriptions.',
        '<strong>Import:</strong> Uploads the JSON to re-create subscriptions in the target store.',
        'Preserves: product reference, variant, and subscriber email.',
      ],
    ],
  ];
  $manualNote = $manualNotes[$title] ?? null;
@endphp

@if($manualNote)
<div class="mt-4 rounded-lg border border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 p-4">
  <div class="flex items-start gap-2 mb-2">
    <svg class="w-4 h-4 mt-0.5 shrink-0 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd"/>
    </svg>
    <p class="text-sm font-semibold text-blue-800 dark:text-blue-300">{{ $manualNote['title'] }}</p>
  </div>
  <ul class="ml-6 space-y-1 list-disc text-sm text-blue-700 dark:text-blue-300">
    @foreach($manualNote['points'] as $point)
      <li>{!! $point !!}</li>
    @endforeach
  </ul>
</div>
@endif

{{-- Footer (checkbox gates Next) --}}
<div class="mt-4 sm:mt-6" x-data="{ confirmed: false }" x-cloak>
  <div class="sticky bottom-2 sm:static">
    <div class="bg-gray-800/90 backdrop-blur rounded-lg p-3 sm:p-0 flex flex-col items-center sm:bg-transparent">
      <div class="mb-3 flex items-center sm:mb-4">
        <label for="confirm-{{ $idx }}" class="ml-2 text-sm text-gray-300">
          If you have already migrated <b class="text-blue-600">"{{ $title }}"</b>. Click on the checbox
        </label>
        &nbsp;
        <input
          id="confirm-{{ $idx }}"
          type="checkbox"
          class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500"
          x-model="confirmed"
        >
      </div>

      <div class="flex gap-2 sm:gap-3">
        <button
          type="button"
          class="btn-prev rounded-lg bg-gray-700 px-4 py-2 text-gray-200 hover:bg-gray-600 sm:px-6"
          {{ $idx == 1 ? 'disabled' : '' }}
        >
          Prev
        </button>

        <button
          type="button"
          class="btn-next rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 sm:px-6"
          :class="{ 'hidden': !confirmed }"
          :disabled="!confirmed"
          aria-disabled="true"
        >
          {{ $idx < 10 ? 'Next' : 'Finish' }}
        </button>
      </div>
    </div>
  </div>
</div>

