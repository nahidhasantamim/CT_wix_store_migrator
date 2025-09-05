<x-app-layout>

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12 bg-gray-100 dark:bg-gray-800">
        <div class="container mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-900 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="">

                        @if(session('success'))
                            <div class="my-2 px-4 py-3 rounded bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 border border-green-300 dark:border-green-700">
                                {!! session('success') !!}
                            </div>
                        @endif
                        @if(session('error'))
                            <div class="my-2 px-4 py-3 rounded bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 border border-red-300 dark:border-red-700">
                                {!! session('error') !!}
                            </div>
                        @endif

                        @if(count($stores) === 0)
                                <div class="my-3 px-4 py-3 rounded bg-yellow-100 dark:bg-yellow-900 text-yellow-900 dark:text-yellow-100 border border-yellow-300 dark:border-yellow-700">
                                    <b>No Wix stores are connected yet.</b>
                                    <p class="mt-1 text-sm text-yellow-800 dark:text-yellow-200">
                                        To connect, please open this app <b>from your Wix site dashboard</b> (where it will pass the instance token),
                                        or use a Wix install/team member invite link.<br>
                                        <br>
                                        <i>
                                            Once the app is opened from Wix, your connected store(s) will appear here automatically.
                                        </i>
                                    </p>
                                </div>
                            @else
                                <h4 class="text-3xl text-center mb-5 text-gray-900 dark:text-gray-100">Connected Wix Stores</h4>


                                

                                <div id="accordion-collapse" class="mb-5" data-accordion="collapse">
                                    {{-- Automatic Migration --}}
                                    <h2 id="accordion-collapse-heading-1">
                                        <button type="button" class="flex items-center justify-between w-full p-5 font-medium rtl:text-right text-gray-500 border border-b-0 border-gray-200 rounded-t-sm focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-800 dark:border-gray-700 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 gap-3" data-accordion-target="#accordion-collapse-body-1" aria-expanded="true" aria-controls="accordion-collapse-body-1">
                                        <span># Automatic Migration</span>
                                        <svg data-accordion-icon class="w-3 h-3 rotate-180 shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5 5 1 1 5"/>
                                        </svg>
                                        </button>
                                    </h2>
                                    <div id="accordion-collapse-body-1" class="hidden" aria-labelledby="accordion-collapse-heading-1">
                                        <div class="w-full bg-white border border-gray-200 shadow-sm dark:bg-gray-900 dark:border-gray-700 p-5">
                                            <form class="space-y-6" action="{{ route('wix.migrate') }}" method="POST">
                                                @csrf
                                                <div class="flex gap-4">
                                                    <div class="w-1/2">
                                                        <label for="from_store" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">From Store</label>
                                                        <select id="from_store" name="from_store" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                            <option selected>Select Store</option>
                                                            @foreach($stores as $i => $store)
                                                                <option value="{{ $store->instance_id }}">{{ $store->store_name }} ({{ \Illuminate\Support\Str::limit($store->instance_id, 30, '...') }})</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="w-1/2">
                                                        <label for="to_store" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">To Store</label>
                                                        <select id="to_store" name="to_store" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                            <option selected>Select Store</option>
                                                            @foreach($stores as $i => $store)
                                                                <option value="{{ $store->instance_id }}">{{ $store->store_name }} ({{ \Illuminate\Support\Str::limit($store->instance_id, 30, '...') }})</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="flex flex-wrap gap-x-6 gap-y-2 items-center">
                                                    <label class="flex items-center space-x-2">
                                                        <input checked id="checkbox-collections" type="checkbox" name="migrate_collections" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Collections</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input checked id="checkbox-products" type="checkbox" name="migrate_products" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Products</span>
                                                    </label>
                                                    
                                                    <label class="flex items-center space-x-2">
                                                        <input id="checkbox-contacts" type="checkbox" name="migrate_contacts" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Contacts</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input id="checkbox-members" type="checkbox" name="migrate_members" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Members</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input id="checkbox-orders" type="checkbox" name="migrate_orders" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Orders</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input id="checkbox-discounts" type="checkbox" name="migrate_discounts" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Discount Rules</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input checked id="checkbox-coupons" type="checkbox" name="migrate_coupons" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Coupons</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input id="checkbox-payments" type="checkbox" name="migrate_payments" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Payments</span>
                                                    </label>
                                                    <label class="flex items-center space-x-2">
                                                        <input id="checkbox-media" type="checkbox" name="migrate_media" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Media</span>
                                                    </label>
                                                     <label class="flex items-center space-x-2">
                                                        <input id="checkbox-media" type="checkbox" name="migrate_loyalty" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded-sm focus:ring-blue-500 dark:focus:ring-blue-600 dark:bg-gray-700 dark:border-gray-600">
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-300">Loyalty Programs</span>
                                                    </label>
                                                </div>

                                                <button type="submit" class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">Start Migration</button>
                                            </form>
                                        </div>
                                    </div>

                                    {{-- Manual Migration --}}
                                    <h2 id="accordion-collapse-heading-3">
                                        <button type="button" class="flex items-center justify-between w-full p-5 font-medium rtl:text-right text-gray-500 border border-gray-200 rounded-b-sm focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-800 dark:border-gray-700 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 gap-3" data-accordion-target="#accordion-collapse-body-3" aria-expanded="false" aria-controls="accordion-collapse-body-3">
                                        <span># Manual Migration</span>
                                        <svg data-accordion-icon class="w-3 h-3 rotate-180 shrink-0" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5 5 1 1 5"/>
                                        </svg>
                                        </button>
                                    </h2>
                                    <div id="accordion-collapse-body-3" class="hidden" aria-labelledby="accordion-collapse-heading-3">
                                        <div class="p-5 border border-t-0 border-gray-200 dark:border-gray-700">
                                            <div class="grid grid-cols-1 sm:grid-cols-1 md:grid-cols-2 mx-auto gap-4">
                                                @foreach($stores as $i => $store)
                                                    <div class="w-full bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-gray-900 dark:border-gray-700">
                                                        <div class="flex justify-end px-4 pt-4">
                                                            <button id="dropdownButton{{ $store->id }}" data-dropdown-toggle="dropdown{{ $store->id }}" class="inline-block text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:ring-4 focus:outline-none focus:ring-gray-200 dark:focus:ring-gray-700 rounded-lg text-sm p-1.5" type="button">
                                                                <span class="sr-only">Open dropdown</span>
                                                                <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 3">
                                                                    <path d="M2 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6.041 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM14 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>
                                                                </svg>
                                                            </button>
                                                            <!-- Dropdown menu -->
                                                            <div id="dropdown{{ $store->id }}" class="z-10 hidden text-base list-none bg-gray-100 dark:bg-gray-800 divide-y divide-gray-100 rounded-lg shadow-sm w-44">
                                                                <ul class="py-2" aria-labelledby="dropdownButton{{ $store->id }}">
                                                                    <li>
                                                                        <a href="#" data-modal-target="authentication-modal{{ $store->id }}" data-modal-toggle="authentication-modal{{ $store->id }}"  class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 dark:text-gray-200 dark:hover:text-white">Rename Store</a>
                                                                    </li>
                                                                    <li>
                                                                        <button 
                                                                            type="button"
                                                                            class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-600 dark:text-gray-200 dark:hover:text-white"
                                                                            x-data=""
                                                                            x-on:click.prevent="$dispatch('open-modal', 'confirm-store-deletion-{{ $store->id }}')"
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    </li>
                                                                </ul>
                                                            </div>

                                                            <!-- Update modal -->
                                                            <div id="authentication-modal{{ $store->id }}" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full">
                                                                <div class="relative p-4 w-full max-w-md max-h-full">
                                                                    <!-- Modal content -->
                                                                    <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
                                                                        <!-- Modal header -->
                                                                        <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600 border-gray-200">
                                                                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                                                                                Update Store Info
                                                                            </h3>
                                                                            <button type="button" class="end-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="authentication-modal{{ $store->id }}">
                                                                                <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                                                                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                                                                                </svg>
                                                                                <span class="sr-only">Close modal</span>
                                                                            </button>
                                                                        </div>
                                                                        <!-- Modal body -->
                                                                        <div class="p-4 md:p-5">
                                                                            <form class="space-y-4" action="{{ route('stores.update', $store) }}" method="POST" enctype="multipart/form-data">
                                                                                @csrf
                                                                                <div>
                                                                                    <label for="store_name" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Store Name</label>
                                                                                    <input type="text" name="store_name" id="store_name" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full dark:bg-gray-600 dark:border-gray-500 dark:placeholder-gray-400 dark:text-white" placeholder="Coalition Technologies" value="{{ $store->store_name }}" required />
                                                                                </div>
                                                                                <div>
                                                                                    <label for="Store Logo" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Store Logo</label>
                                                                                    <input type="file" name="store_logo" id="store_logo" placeholder="Store Logo" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full dark:bg-gray-600 dark:border-gray-500 dark:placeholder-gray-400 dark:text-white" />
                                                                                </div>
                                                                                <button type="submit" class="w-full text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">Update</button>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div> 

                                                            <!-- delete modal -->
                                                            <x-modal name="confirm-store-deletion-{{ $store->id }}" :show="false" focusable>
                                                                <form method="POST" action="{{ route('stores.destroy', $store->id) }}" class="p-6">
                                                                    @csrf
                                                                    @method('DELETE')

                                                                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                                                                        {{ __('Delete Store') }}
                                                                    </h2>

                                                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                                                        {{ __("Once your store is deleted, all associated logs and data will be permanently deleted. This action cannot be undone.") }}
                                                                    </p>

                                                                    <div class="mt-6 flex justify-end">
                                                                        <x-secondary-button x-on:click="$dispatch('close')">
                                                                            {{ __('Cancel') }}
                                                                        </x-secondary-button>

                                                                        <x-danger-button class="ms-3">
                                                                            {{ __('Delete Store') }}
                                                                        </x-danger-button>
                                                                    </div>
                                                                </form>
                                                            </x-modal>

                                                        </div>
                                                        <div class="flex flex-col items-center">
                                                            @if($store->store_logo)
                                                                <img class="w-24 h-24 mb-3 rounded-full shadow-lg border-4 border-dashed border-blue-800"
                                                                    src="{{ asset('storage/' . $store->store_logo) }}"
                                                                    alt="{{ $store->store_name ?? 'Wix Store Logo' }}" />
                                                            @else
                                                                <img class="w-24 h-24 mb-3 rounded-full shadow-lg" src="https://img.icons8.com/external-tal-revivo-color-tal-revivo/96/external-wixcom-ltd-is-an-israeli-cloud-based-web-development-logo-color-tal-revivo.png" alt="{{ $store->store_name ?? 'Wix Store Logo' }}" />
                                                            @endif

                                                            <h5 class="mb-1 text-xl font-medium text-gray-900 dark:text-white">{{ $store->store_name }}</h5>
                                                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                                                Instance ID: ({{ \Illuminate\Support\Str::limit($store->instance_id, 30, '...') }})
                                                            </span>

                                                            <div class="flex mt-4 md:mt-6">

                                                                <div class="w-full bg-white dark:bg-gray-900">
                                                                    <div class="sm:hidden">
                                                                        <label for="tabs" class="sr-only">Select tab</label>
                                                                        <select id="tabs" class="bg-gray-50 border-0 border-b border-gray-200 text-gray-900 text-sm focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-900 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                                                            <option>Media</option>
                                                                            {{-- <option>Catalog</option> --}}
                                                                            <option>Categories</option>
                                                                            <option>Products</option>
                                                                            <option>Contacts</option>
                                                                            {{-- <option>Members</option> --}}
                                                                            <option>Orders</option>
                                                                            <option>Discounts</option>
                                                                            <option>Coupons</option>
                                                                            <option>Payments</option>
                                                                            <option>Loyalty Programs</option>
                                                                        </select>
                                                                    </div>
                                                                    



                                                                    <ul class="grid grid-cols-5 gap-1 text-sm font-medium text-center text-gray-500 divide-x divide-gray-200 dark:divide-gray-600 dark:text-gray-400 rtl:divide-x-reverse" id="fullWidthTab{{ $store->id }}" data-tabs-toggle="#fullWidthTabContent{{ $store->id }}" role="tablist">
                                                                        <li class="w-full">
                                                                            <button id="media{{ $store->id }}-tab" data-tabs-target="#media{{ $store->id }}" type="button" role="tab" aria-controls="media" aria-selected="true" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Media</button>
                                                                        </li>
                                                                        {{-- <li class="w-full">
                                                                            <button id="catalog{{ $store->id }}-tab" data-tabs-target="#catalog{{ $store->id }}" type="button" role="tab" aria-controls="categories" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Catalog</button>
                                                                        </li> --}}
                                                                        <li class="w-full">
                                                                            <button id="categories{{ $store->id }}-tab" data-tabs-target="#categories{{ $store->id }}" type="button" role="tab" aria-controls="categories" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Categories</button>
                                                                        </li>
                                                                        <li class="w-full">
                                                                            <button id="products{{ $store->id }}-tab" data-tabs-target="#products{{ $store->id }}" type="button" role="tab" aria-controls="products" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Products</button>
                                                                        </li>
                                                                        
                                                                        <li class="w-full">
                                                                            <button id="contacts{{ $store->id }}-tab" data-tabs-target="#contacts{{ $store->id }}" type="button" role="tab" aria-controls="contacts" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Contacts & Members</button>
                                                                        </li>
                                                                        {{-- <li class="w-full">
                                                                            <button id="members{{ $store->id }}-tab" data-tabs-target="#members{{ $store->id }}" type="button" role="tab" aria-controls="members" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-red-700 dark:hover:bg-gray-600">Members</button>
                                                                        </li> --}}
                                                                        <li class="w-full">
                                                                            <button id="orders{{ $store->id }}-tab" data-tabs-target="#orders{{ $store->id }}" type="button" role="tab" aria-controls="orders" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Orders</button>
                                                                        </li>
                                                                        <li class="w-full">
                                                                            <button id="discounts{{ $store->id }}-tab" data-tabs-target="#discounts{{ $store->id }}" type="button" role="tab" aria-controls="discounts" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Discounts</button>
                                                                        </li>
                                                                        <li class="w-full">
                                                                            <button id="coupons{{ $store->id }}-tab" data-tabs-target="#coupons{{ $store->id }}" type="button" role="tab" aria-controls="coupons" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Coupons</button>
                                                                        </li>

                                                                        <li class="w-full">
                                                                            <button id="gift{{ $store->id }}-tab" data-tabs-target="#gift{{ $store->id }}" type="button" role="tab" aria-controls="gift" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Gift Cards</button>
                                                                        </li>

                                                                        {{-- <li class="w-full">
                                                                            <button id="payments{{ $store->id }}-tab" data-tabs-target="#payments{{ $store->id }}" type="button" role="tab" aria-controls="payments" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-red-700 dark:hover:bg-gray-600">Payments</button>
                                                                        </li> --}}
                                                                       
                                                                        <li class="w-full">
                                                                            <button id="loyalty{{ $store->id }}-tab" data-tabs-target="#loyalty{{ $store->id }}" type="button" role="tab" aria-controls="loyalty" aria-selected="false" class="inline-block w-full p-1 h-12 bg-gray-50 hover:bg-gray-100 focus:outline-none dark:bg-green-700 dark:hover:bg-gray-600">Loyality</button>
                                                                        </li>
                                                                         
                                                                    </ul>


                                                                    <div id="fullWidthTabContent{{ $store->id }}" class="bg-white dark:bg-gray-900">
                                                                        {{-- Media Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="media{{ $store->id }}" role="tabpanel" aria-labelledby="media-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Media</h3>
                                                                                        <a href="{{ route('wix.export.media', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Media</h3> 

                                                                                        <form action="{{ route('wix.import.media', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="media_json" accept=".json" id="media" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Categories Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="catalog{{ $store->id }}" role="tabpanel" aria-labelledby="catalog-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                                                                </svg>
                                                                            </div>
                                                                                
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Catalog</h3>
                                                                                        <a href="{{ route('wix.export.catalog', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Catalog</h3> 

                                                                                        <form action="{{ route('wix.import.catalog', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="catalog_json" accept=".json" id="categories" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>
                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Categories Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="categories{{ $store->id }}" role="tabpanel" aria-labelledby="categories-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                                                                </svg>
                                                                            </div>
                                                                                
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Categories</h3>
                                                                                        <a href="{{ route('wix.export.categories', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Categories</h3> 

                                                                                        <form action="{{ route('wix.import.categories', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="categories_json" accept=".json" id="categories" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>
                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Products Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="products{{ $store->id }}" role="tabpanel" aria-labelledby="products-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="size-12">
                                                                                    <path d="M4.30104 7.5C4.03162 7.97242 4.02713 8.54509 4.01813 9.69042L4 12L4.01813 14.3096C4.02713 15.4549 4.03162 16.0276 4.30104 16.5C4.57046 16.9724 5.05809 17.2627 6.03336 17.8432L8 19.0139L9.98478 20.1528C10.969 20.7176 11.4612 21 12 21M4.30104 7.5C4.57046 7.02758 5.05809 6.7373 6.03336 6.15675L8 4.98606L9.98478 3.84717C10.969 3.28239 11.4612 3 12 3C12.5388 3 13.031 3.28239 14.0152 3.84717L16 4.98606L17.9666 6.15675C18.9419 6.7373 19.4295 7.02758 19.699 7.5M4.30104 7.5L12 12M12 21C12.5388 21 13.031 20.7176 14.0152 20.1528L16 19.0139L17.9666 17.8432C18.9419 17.2627 19.4295 16.9724 19.699 16.5C19.9684 16.0276 19.9729 15.4549 19.9819 14.3096L20 12L19.9819 9.69042C19.9729 8.54509 19.9684 7.97242 19.699 7.5M12 21V12M19.699 7.5L12 12" stroke="currentColor" stroke-width="null" class="my-path"></path>
                                                                                </svg>
                                                                            </div>


                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                
                                                                                    
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Products</h3>
                                                                                        <a href="{{ route('wix.export.products', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Products</h3> 

                                                                                        <form action="{{ route('wix.import.products', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="products_inventory_json" accept=".json" id="products" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>
                                                                       
                                                                        {{-- Contacts Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="contacts{{ $store->id }}" role="tabpanel" aria-labelledby="contacts-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="25" viewBox="0 0 24 25" fill="none" xmlns="http://www.w3.org/2000/svg" class="size-12">
                                                                                    <path d="M16 21.7925V15.7925C16 14.8625 16 14.3975 15.8978 14.016C15.6204 12.9807 14.8117 12.1721 13.7765 11.8947C13.395 11.7925 12.93 11.7925 12 11.7925C11.07 11.7925 10.605 11.7925 10.2235 11.8947C9.18827 12.1721 8.37962 12.9807 8.10222 14.016C8 14.3975 8 14.8625 8 15.7925V21.7925M16 21.7925H8M16 21.7925H20.25C20.9522 21.7925 21.3033 21.7925 21.5556 21.624C21.6648 21.551 21.7585 21.4572 21.8315 21.3481C22 21.0958 22 20.7447 22 20.0425V18.3925C22 17.8351 22 17.5564 21.9631 17.3232C21.7598 16.0395 20.753 15.0327 19.4693 14.8294C19.2361 14.7925 18.9574 14.7925 18.4 14.7925C18.0284 14.7925 17.8426 14.7925 17.6871 14.8171C16.8313 14.9526 16.1602 15.6238 16.0246 16.4796C16 16.6351 16 16.8209 16 17.1925V21.7925ZM8 21.7925V17.1925C8 16.8209 8 16.6351 7.97538 16.4796C7.83983 15.6238 7.16865 14.9526 6.31287 14.8171C6.1574 14.7925 5.9716 14.7925 5.6 14.7925C5.0426 14.7925 4.76389 14.7925 4.5307 14.8294C3.24702 15.0327 2.24025 16.0395 2.03693 17.3232C2 17.5564 2 17.8351 2 18.3925V20.0425C2 20.7447 2 21.0958 2.16853 21.3481C2.24149 21.4572 2.33524 21.551 2.44443 21.624C2.69665 21.7925 3.04777 21.7925 3.75 21.7925H8ZM15 6.79248C15 8.44933 13.6569 9.79248 12 9.79248C10.3431 9.79248 9 8.44933 9 6.79248C9 5.13563 10.3431 3.79248 12 3.79248C13.6569 3.79248 15 5.13563 15 6.79248ZM7 10.7925C7 11.8971 6.10457 12.7925 5 12.7925C3.89543 12.7925 3 11.8971 3 10.7925C3 9.68791 3.89543 8.79248 5 8.79248C6.10457 8.79248 7 9.68791 7 10.7925ZM21 10.7925C21 11.8971 20.1046 12.7925 19 12.7925C17.8954 12.7925 17 11.8971 17 10.7925C17 9.68791 17.8954 8.79248 19 8.79248C20.1046 8.79248 21 9.68791 21 10.7925Z" stroke="currentColor" stroke-width="null" class="my-path"></path>
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Contacts</h3>
                                                                                        <a href="{{ route('wix.export.contacts', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Contacts</h3> 

                                                                                        <form action="{{ route('wix.import.contacts', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="contacts_json" accept=".json" id="contacts" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Orders Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="orders{{ $store->id }}" role="tabpanel" aria-labelledby="orders-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" class="size-10">
                                                                                    <path d="M13 6.94949C13.6353 7.59777 14.5207 8 15.5 8C16.4793 8 17.3647 7.59777 18 6.94949M13 6.94949C12.3814 6.31822 12 5.45365 12 4.5C12 2.567 13.567 1 15.5 1C17.433 1 19 2.567 19 4.5C19 5.45365 18.6186 6.31822 18 6.94949M13 6.94949V10.3238C13 10.6792 13 10.857 13.0299 10.9764C13.1687 11.5314 13.7438 11.857 14.2911 11.6905C14.4089 11.6547 14.5613 11.5632 14.8661 11.3804C14.9816 11.311 15.0394 11.2764 15.0968 11.2511C15.3537 11.1379 15.6463 11.1379 15.9032 11.2511C15.9606 11.2764 16.0184 11.311 16.1339 11.3804C16.4387 11.5632 16.5911 11.6547 16.7089 11.6905C17.2562 11.857 17.8313 11.5314 17.9701 10.9764C18 10.857 18 10.6792 18 10.3238V6.94949M9.57143 1H7C4.17157 1 2.75736 1 1.87868 1.87868C1 2.75736 1 4.17157 1 7V14C1 15.8692 1 16.8038 1.40192 17.5C1.66523 17.9561 2.04394 18.3348 2.5 18.5981C3.19615 19 4.13077 19 6 19M6 19H17C18.1046 19 19 18.1046 19 17C19 15.8954 18.1046 15 17 15H11.7082C9.90398 15 9.00186 15 8.27691 15.448C7.55195 15.8961 7.14852 16.703 6.34164 18.3167L6 19ZM9 7H4M9 11H4" stroke="currentColor" stroke-width="null" stroke-linecap="round" class="my-path"></path>
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Orders</h3>
                                                                                        <a href="{{ route('wix.export.orders', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Orders</h3> 

                                                                                        <form action="{{ route('wix.import.orders', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="orders_json" accept=".json" id="orders" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Discounts Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="discounts{{ $store->id }}" role="tabpanel" aria-labelledby="discounts-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="24"  xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.99 14.993 6-6m6 3.001c0 1.268-.63 2.39-1.593 3.069a3.746 3.746 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043 3.745 3.745 0 0 1-3.068 1.593c-1.268 0-2.39-.63-3.068-1.593a3.745 3.745 0 0 1-3.296-1.043 3.746 3.746 0 0 1-1.043-3.297 3.746 3.746 0 0 1-1.593-3.068c0-1.268.63-2.39 1.593-3.068a3.746 3.746 0 0 1 1.043-3.297 3.745 3.745 0 0 1 3.296-1.042 3.745 3.745 0 0 1 3.068-1.594c1.268 0 2.39.63 3.068 1.593a3.745 3.745 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.297 3.746 3.746 0 0 1 1.593 3.068ZM9.74 9.743h.008v.007H9.74v-.007Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 4.5h.008v.008h-.008v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Discount Rules</h3>
                                                                                        <a href="{{ route('wix.export.discount.rules', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Discount Rules</h3> 

                                                                                        <form action="{{ route('wix.import.discount.rules', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="discount_rules_json" accept=".json" id="discount_rules" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                         {{-- Coupons Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="coupons{{ $store->id }}" role="tabpanel" aria-labelledby="coupons-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="size-12">
                                                                                    <path d="M8 5V8M8 19V16M8 10V11M8 13V14M11 9H16M11 12H16M11 15H16M4 5H20C20.9428 5 21.4142 5 21.7071 5.29289C22 5.58579 22 6.05719 22 7V8.90323C22 8.95667 21.9567 9 21.9032 9C20.2998 9 19 10.2998 19 11.9032V12.0968C19 13.7002 20.2998 15 21.9032 15C21.9567 15 22 15.0433 22 15.0968V17C22 17.9428 22 18.4142 21.7071 18.7071C21.4142 19 20.9428 19 20 19H4C3.05719 19 2.58579 19 2.29289 18.7071C2 18.4142 2 17.9428 2 17V15.0968C2 15.0433 2.04333 15 2.09677 15C3.70018 15 5 13.7002 5 12.0968V11.9032C5 10.2998 3.70018 9 2.09677 9C2.04333 9 2 8.95667 2 8.90323V7C2 6.05719 2 5.58579 2.29289 5.29289C2.58579 5 3.05719 5 4 5Z" stroke="currentColor" stroke-width="null" stroke-linecap="round" stroke-linejoin="round" class="my-path"></path>
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Coupons</h3>
                                                                                        <a href="{{ route('wix.export.coupons', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Coupons</h3> 

                                                                                        <form action="{{ route('wix.import.coupons', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="coupons_json" accept=".json" id="coupons" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Gift Cards Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="gift{{ $store->id }}" role="tabpanel" aria-labelledby="gift-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="size-12">
                                                                                    <path d="M8 5V8M8 19V16M8 10V11M8 13V14M11 9H16M11 12H16M11 15H16M4 5H20C20.9428 5 21.4142 5 21.7071 5.29289C22 5.58579 22 6.05719 22 7V8.90323C22 8.95667 21.9567 9 21.9032 9C20.2998 9 19 10.2998 19 11.9032V12.0968C19 13.7002 20.2998 15 21.9032 15C21.9567 15 22 15.0433 22 15.0968V17C22 17.9428 22 18.4142 21.7071 18.7071C21.4142 19 20.9428 19 20 19H4C3.05719 19 2.58579 19 2.29289 18.7071C2 18.4142 2 17.9428 2 17V15.0968C2 15.0433 2.04333 15 2.09677 15C3.70018 15 5 13.7002 5 12.0968V11.9032C5 10.2998 3.70018 9 2.09677 9C2.04333 9 2 8.95667 2 8.90323V7C2 6.05719 2 5.58579 2.29289 5.29289C2.58579 5 3.05719 5 4 5Z" stroke="currentColor" stroke-width="null" stroke-linecap="round" stroke-linejoin="round" class="my-path"></path>
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Gift Cards</h3>
                                                                                        <a href="{{ route('wix.export.gift.cards', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Gift Cards</h3> 

                                                                                        <form action="{{ route('wix.import.gift.cards', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="giftcards_json" accept=".json" id="coupons" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Payments Section --}}
                                                                        {{-- <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="payments{{ $store->id }}" role="tabpanel" aria-labelledby="payments-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Payments</h3>
                                                                                        <a href="{{ route('wix.export.orders', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Payments</h3> 

                                                                                        <form action="{{ route('wix.import.orders', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="payments_json" accept=".json" id="payments" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div> --}}

                                                                        {{-- Loyality Program Section --}}
                                                                        <div class="hidden p-4 bg-white md:p-8 dark:bg-gray-900" id="loyalty{{ $store->id }}" role="tabpanel" aria-labelledby="loyalty-tab">
                                                                            <div class="text-center flex justify-center items-center mb-5">
                                                                                <svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-12">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                                                                </svg>
                                                                            </div>
                                                                            <div class="grid border border-gray-200 rounded-lg shadow-xs dark:border-gray-700 md:grid-cols-1 bg-white dark:bg-gray-800">
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 rounded-t-lg md:rounded-t-none md:rounded-ss-lg md:border-e dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="max-w-4xl mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Export Loyalty Programs</h3>
                                                                                        <a href="{{ route('wix.loyalty.export', $store) }}" class="text-white bg-blue-800 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-200 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-900 font-medium rounded-lg text-sm px-5 py-2.5 inline-flex justify-center w-full text-center">
                                                                                            Download (JSON)
                                                                                        </a>
                                                                                    </blockquote>
                                                                                </figure>
                                                                                <figure class="flex flex-col items-center justify-center p-2 text-center bg-white border-b border-gray-200 md:rounded-se-lg dark:bg-gray-800 dark:border-gray-700">
                                                                                    <blockquote class="w-full px-2 mx-auto mb-4 text-gray-500 lg:mb-8 dark:text-gray-400">
                                                                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Import Loyalty Programs</h3> 

                                                                                        <form action="{{ route('wix.loyalty.import', $store) }}" method="POST" enctype="multipart/form-data" class="">
                                                                                            @csrf
                                                                                            <div class="flex">
                                                                                                <div class="relative w-full">
                                                                                                    <input type="file" name="loyalty_accounts_json" accept=".json" id="loyalty" class="block w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg rounded-s-gray-100 rounded-s-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" placeholder="Search" required />
                                                                                                    <button type="submit" class="absolute top-0 end-0 p-2.5 h-full text-sm font-medium text-white bg-blue-800 rounded-e-lg border border-blue-700 hover:bg-blue-900 focus:ring-4 focus:outline-none focus:ring-blue-300 dark:bg-blue-600 dark:hover:bg-blue-800 dark:focus:ring-blue-800">
                                                                                                        Upload (JSON)
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </form>
                                                                                    </blockquote>

                                                                                </figure>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>

                               
                            @endif

                            @if(!empty($last_accessed_store))
                                <hr>
                                <p>
                                    <b>Last accessed store:</b>
                                    {{ $last_accessed_store->store_name }} (<code>{{ \Illuminate\Support\Str::limit($last_accessed_store->instance_id, 30, '...') }}</code>)
                                </p>
                            @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
