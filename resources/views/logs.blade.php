<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">
            {{ __('Logs') }}
        </h2>
    </x-slot>

    <div class="py-12 bg-gray-100 dark:bg-gray-800">
        <div class="container mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-900 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="container">

                        @if(session('success'))
                            <div class="alert alert-success my-2">{{ session('success') }}</div>
                        @endif

                        <h4 class="text-3xl text-center mb-5 text-gray-900 dark:text-gray-100">Migration Logs</h4>

                        <div class="relative overflow-x-auto">
                            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400" id="search-table">
                                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                                    <tr>
                                        <th scope="col" class="">
                                            Date Created
                                        </th>
                                        {{-- <th scope="col" class="">
                                            Store Name
                                        </th> --}}
                                        <th scope="col" class="">
                                            Action
                                        </th>
                                        <th scope="col" class="">
                                            Status
                                        </th>
                                        <th scope="col" class="">
                                            Details
                                        </th>
                                        
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($logs as $log)
                                        <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 border-gray-200">
                                            <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                                                {{ $log->created_at->format('d-M-Y h:ia') }}
                                            </td>
                                            {{-- <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                                                {{ $log->store?->store_name ?? 'N/A' }}
                                            </td> --}}
                                            <th class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                                                {{ $log->action }}
                                            </th>
                                           <td class="px-6 py-4 font-medium whitespace-nowrap 
                                                @if($log->status === 'success') text-green-600
                                                @elseif($log->status === 'delete') text-red-600
                                                @elseif($log->status === 'error') text-red-600
                                                @else text-gray-900 dark:text-white
                                                @endif">
                                                {{ $log->status }}
                                            </td>

                                            <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                                                {{ $log->details }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">
                            {{ $logs->links() }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
