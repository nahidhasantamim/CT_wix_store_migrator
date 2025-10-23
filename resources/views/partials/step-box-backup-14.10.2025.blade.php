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
                        <input type="date" name="date_from" id="date_from_{{ $idx }}_{{ $store->id }}"
                              placeholder="YYYY-MM-DD or DD.MM.YYYY"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              :disabled="!useRange">
                      </div>
                      <div>
                        <label class="block text-xs text-gray-300 mb-1" for="date_to_{{ $idx }}_{{ $store->id }}">End date</label>
                        <input type="date" name="date_to" id="date_to_{{ $idx }}_{{ $store->id }}"
                              placeholder="YYYY-MM-DD or DD.MM.YYYY"
                              class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                              :disabled="!useRange">
                      </div>
                    </div>

                    <p class="text-[11px] text-gray-400">
                      Dates are interpreted in <span class="font-medium text-gray-200">Pacific Time (PT)</span>, inclusive start/end of day.  
                      Accepts <code>YYYY-MM-DD</code>, <code>DD.MM.YYYY</code>, or <code>MM/DD/YYYY</code>.
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

                @else
                  {{-- Default export for all other entities --}}
                  <a href="{{ route($conf['export'], $store) }}"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60">
                    Download
                  </a>
                @endif
              </div>

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
                           class="block w-full cursor-pointer rounded-lg border border-gray-600 bg-gray-900 text-sm text-gray-200 file:mr-2 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-gray-700 file:px-3 file:py-2 file:text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                           required>
                    <button type="submit"
                            class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/60 sm:absolute sm:top-0 sm:right-0 sm:mt-0 sm:h-full sm:w-auto sm:rounded-l-none">
                      Upload 
                    </button>
                  </div>
                </form>
              </div>
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
</div>

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
          {{ $idx < 9 ? 'Next' : 'Finish' }}
        </button>
      </div>
    </div>
  </div>
</div>

