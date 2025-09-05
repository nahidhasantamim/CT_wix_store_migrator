<x-guest-layout>
    <div class="w-full p-4 text-center bg-white border border-gray-200 rounded-lg shadow-sm sm:p-8 dark:bg-gray-800 dark:border-gray-700">
        <h5 class="mb-2 text-3xl font-bold text-gray-900 dark:text-white">A Complete Wix Store Migrator</h5>

        <p class="mb-5 text-base text-gray-500 sm:text-lg dark:text-gray-400">Lorem ipsum dolor sit amet consectetur adipisicing elit. Fuga fugiat sapiente assumenda aliquam enim animi. </p> 
        <div class="items-center justify-center space-y-4 sm:flex sm:space-y-0 sm:space-x-4 rtl:space-x-reverse">
            @auth
                <a href="{{ route('wix.dashboard') }}" class="w-full sm:w-auto bg-gray-800 hover:bg-gray-700 focus:ring-4 focus:outline-none focus:ring-gray-300 text-white rounded-lg inline-flex items-center justify-center px-4 py-2.5 dark:bg-gray-700 dark:hover:bg-gray-600 dark:focus:ring-gray-700">
                    <div class="text-left rtl:text-right">
                        <div class="font-sans text-sm font-semibold">Dashboard</div>
                    </div>
                </a>
            @else
                <a href="{{ route('login') }}" class="w-full sm:w-auto bg-gray-800 hover:bg-gray-700 focus:ring-4 focus:outline-none focus:ring-gray-300 text-white rounded-lg inline-flex items-center justify-center px-4 py-2.5 dark:bg-gray-700 dark:hover:bg-gray-600 dark:focus:ring-gray-700">
                    <div class="text-left rtl:text-right">
                        <div class="font-sans text-sm font-semibold">Log in</div>
                    </div>
                </a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="w-full sm:w-auto bg-gray-800 hover:bg-gray-700 focus:ring-4 focus:outline-none focus:ring-gray-300 text-white rounded-lg inline-flex items-center justify-center px-4 py-2.5 dark:bg-gray-700 dark:hover:bg-gray-600 dark:focus:ring-gray-700">
                        <div class="text-left rtl:text-right">
                            <div class="font-sans text-sm font-semibold">Register</div>
                        </div>
                    </a>
                @endif
            @endauth

        </div>
    </div>
</x-guest-layout>
