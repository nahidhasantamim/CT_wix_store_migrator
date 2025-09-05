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
          <div>

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
                  or use a Wix install/team member invite link.<br><br>
                  <i>Once the app is opened from Wix, your connected store(s) will appear here automatically.</i>
                </p>
              </div>
            @else
              <h4 class="text-3xl text-center mb-5 text-gray-900 dark:text-gray-100">CT Store Migrator</h4>

              <div id="accordion-collapse" class="mb-5" data-accordion="collapse">
                {{-- =================== AUTOMATIC MIGRATION =================== --}}
                <h2 id="accordion-collapse-heading-1">
                  <button type="button"
                          class="flex items-center justify-between w-full p-5 font-medium rtl:text-right text-gray-500 border border-b-0 border-gray-200 rounded-t-xl focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-800 dark:border-gray-700 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 gap-3"
                          data-accordion-target="#accordion-collapse-body-1" aria-expanded="true"
                          aria-controls="accordion-collapse-body-1">
                    <span># Automatic Migration</span>
                    <svg data-accordion-icon class="w-3 h-3 shrink-0 transition-transform duration-200" aria-hidden="true"
                         xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                      <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5 5 1 1 5"/>
                    </svg>
                  </button>
                </h2>
                <div id="accordion-collapse-body-1" class="hidden" aria-labelledby="accordion-collapse-heading-1">

                  {{-- ✅ SCOPE THE AUTO WIZARD --}}
                  <div id="auto-wizard" class="mx-auto p-4 sm:p-6 border border-b-0 border-gray-200 dark:border-gray-700">
                    {{-- Mobile progress (use class, not duplicated ID) --}}
                    <div class="h-1 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden sm:hidden mb-3">
                      <div class="mobile-progress h-1 bg-blue-600 transition-all duration-300" style="width: 11.11%;"></div>
                    </div>

                    {{-- Stepper (use class, not duplicated ID) --}}
                    <ol class="stepper flex items-center gap-3 overflow-x-auto snap-x snap-mandatory pb-2
                               text-[13px] sm:text-sm font-medium text-gray-500 dark:text-gray-400">
                      {{-- 1. Categories --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="1">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-blue-600 text-white">1</span>
                          <span class="step-label whitespace-nowrap">Categories</span>
                        </span>
                      </li>
                      {{-- 2. Products --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="2">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">2</span>
                          <span class="step-label whitespace-nowrap">Products</span>
                        </span>
                      </li>
                      {{-- 3. Contacts & Members --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="3">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">3</span>
                          <span class="step-label whitespace-nowrap">Contacts &amp; Members</span>
                        </span>
                      </li>
                      {{-- 4. Orders --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="4">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">4</span>
                          <span class="step-label whitespace-nowrap">Orders</span>
                        </span>
                      </li>
                      {{-- 5. Discounts --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="5">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">5</span>
                          <span class="step-label whitespace-nowrap">Discounts</span>
                        </span>
                      </li>
                      {{-- 6. Coupons --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="6">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">6</span>
                          <span class="step-label whitespace-nowrap">Coupons</span>
                        </span>
                      </li>
                      {{-- 7. Gift Cards --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="7">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">7</span>
                          <span class="step-label whitespace-nowrap">Gift Cards</span>
                        </span>
                      </li>
                      {{-- 8. Loyalty --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="8">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">8</span>
                          <span class="step-label whitespace-nowrap">Loyalty</span>
                        </span>
                      </li>
                      {{-- 9. Media --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1" data-step="9">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">9</span>
                          <span class="step-label whitespace-nowrap">Media</span>
                        </span>
                      </li>
                    </ol>

                    {{-- PANELS (AUTO) --}}
                    <section class="step-panel" data-step="1">
                      @include('partials.auto-step-box', ['title' => 'Categories', 'idx' => 1])
                    </section>
                    <section class="step-panel hidden" data-step="2">
                      @include('partials.auto-step-box', ['title' => 'Products', 'idx' => 2])
                    </section>
                    <section class="step-panel hidden" data-step="3">
                      @include('partials.auto-step-box', ['title' => 'Contacts & Members', 'idx' => 3])
                    </section>
                    <section class="step-panel hidden" data-step="4">
                      @include('partials.auto-step-box', ['title' => 'Orders', 'idx' => 4])
                    </section>
                    <section class="step-panel hidden" data-step="5">
                      @include('partials.auto-step-box', ['title' => 'Discounts', 'idx' => 5])
                    </section>
                    <section class="step-panel hidden" data-step="6">
                      @include('partials.auto-step-box', ['title' => 'Coupons', 'idx' => 6])
                    </section>
                    <section class="step-panel hidden" data-step="7">
                      @include('partials.auto-step-box', ['title' => 'Gift Cards', 'idx' => 7])
                    </section>
                    <section class="step-panel hidden" data-step="8">
                      @include('partials.auto-step-box', ['title' => 'Loyalty', 'idx' => 8])
                    </section>
                    <section class="step-panel hidden" data-step="9">
                      @include('partials.auto-step-box', ['title' => 'Media', 'idx' => 9])
                    </section>
                  </div>
                </div>

                {{-- =================== MANUAL MIGRATION =================== --}}
                <h2 id="accordion-collapse-heading-3">
                  <button type="button"
                          class="flex items-center justify-between w-full p-5 font-medium rtl:text-right text-gray-500 border border-gray-200 rounded-b-sm focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-800 dark:border-gray-700 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 gap-3"
                          data-accordion-target="#accordion-collapse-body-3" aria-expanded="false"
                          aria-controls="accordion-collapse-body-3">
                    <span># Manual Migration</span>
                    <svg data-accordion-icon class="w-3 h-3 shrink-0 transition-transform duration-200" aria-hidden="true"
                         xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                      <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5 5 1 1 5"/>
                    </svg>
                  </button>
                </h2>
                <div id="accordion-collapse-body-3" class="hidden" aria-labelledby="accordion-collapse-heading-3">

                  {{-- ✅ SCOPE THE MANUAL WIZARD --}}
                  <div id="manual-wizard" class="mx-auto p-4 sm:p-6 border border-b-0 border-gray-200 dark:border-gray-700">
                    {{-- Mobile progress --}}
                    <div class="h-1 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden sm:hidden mb-3">
                      <div class="mobile-progress h-1 bg-blue-600 transition-all duration-300" style="width: 11.11%;"></div>
                    </div>

                    {{-- Stepper --}}
                    <ol class="stepper flex items-center gap-3 overflow-x-auto snap-x snap-mandatory pb-2
                               text-[13px] sm:text-sm font-medium text-gray-500 dark:text-gray-400">
                      {{-- 1. Categories --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="1">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-blue-600 text-white">1</span>
                          <span class="step-label whitespace-nowrap">Categories</span>
                        </span>
                      </li>
                      {{-- 2. Products --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="2">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">2</span>
                          <span class="step-label whitespace-nowrap">Products</span>
                        </span>
                      </li>
                      {{-- 3. Contacts & Members --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="3">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">3</span>
                          <span class="step-label whitespace-nowrap">Contacts &amp; Members</span>
                        </span>
                      </li>
                      {{-- 4. Orders --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="4">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">4</span>
                          <span class="step-label whitespace-nowrap">Orders</span>
                        </span>
                      </li>
                      {{-- 5. Discounts --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="5">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">5</span>
                          <span class="step-label whitespace-nowrap">Discounts</span>
                        </span>
                      </li>
                      {{-- 6. Coupons --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="6">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">6</span>
                          <span class="step-label whitespace-nowrap">Coupons</span>
                        </span>
                      </li>
                      {{-- 7. Gift Cards --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="7">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">7</span>
                          <span class="step-label whitespace-nowrap">Gift Cards</span>
                        </span>
                      </li>
                      {{-- 8. Loyalty --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1 sm:after:content-[''] sm:after:flex-1 sm:after:h-1
                                 sm:after:border-b sm:after:border-gray-200 dark:sm:after:border-gray-700 sm:after:mx-3" data-step="8">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">8</span>
                          <span class="step-label whitespace-nowrap">Loyalty</span>
                        </span>
                      </li>
                      {{-- 9. Media --}}
                      <li class="step-item flex items-center shrink-0 snap-start sm:flex-1" data-step="9">
                        <span class="flex items-center">
                          <span class="step-dot w-7 h-7 mr-2 text-xs flex items-center justify-center rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">9</span>
                          <span class="step-label whitespace-nowrap">Media</span>
                        </span>
                      </li>
                    </ol>

                    {{-- PANELS (MANUAL) --}}
                    <section class="step-panel" data-step="1">
                      @include('partials.step-box', ['title' => 'Categories', 'idx' => 1])
                    </section>
                    <section class="step-panel hidden" data-step="2">
                      @include('partials.step-box', ['title' => 'Products', 'idx' => 2])
                    </section>
                    <section class="step-panel hidden" data-step="3">
                      @include('partials.step-box', ['title' => 'Contacts & Members', 'idx' => 3])
                    </section>
                    <section class="step-panel hidden" data-step="4">
                      @include('partials.step-box', ['title' => 'Orders', 'idx' => 4])
                    </section>
                    <section class="step-panel hidden" data-step="5">
                      @include('partials.step-box', ['title' => 'Discounts', 'idx' => 5])
                    </section>
                    <section class="step-panel hidden" data-step="6">
                      @include('partials.step-box', ['title' => 'Coupons', 'idx' => 6])
                    </section>
                    <section class="step-panel hidden" data-step="7">
                      @include('partials.step-box', ['title' => 'Gift Cards', 'idx' => 7])
                    </section>
                    <section class="step-panel hidden" data-step="8">
                      @include('partials.step-box', ['title' => 'Loyalty', 'idx' => 8])
                    </section>
                    <section class="step-panel hidden" data-step="9">
                      @include('partials.step-box', ['title' => 'Media', 'idx' => 9])
                    </section>
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

  <style>
    /* Rotate icon only when its button is expanded */
    [data-accordion] [aria-expanded="true"] [data-accordion-icon] {
      transform: rotate(180deg);
    }
  </style>

  {{-- ===== Scoped, persistent steppers for Auto + Manual ===== --}}
  <script>
    (function () {
      function initWizard(rootId, storageKey) {
        const root = document.getElementById(rootId);
        if (!root) return;

        const panels    = [...root.querySelectorAll('.step-panel')];
        const stepItems = [...root.querySelectorAll('.stepper .step-item')];
        const total     = stepItems.length;
        const mobileBar = root.querySelector('.mobile-progress');

        function updateDots(n) {
          stepItems.forEach(item => {
            const step = +item.dataset.step;
            const dot  = item.querySelector('.step-dot');
            if (!dot) return;
            if (step <= n) {
              dot.classList.remove('bg-gray-200','text-gray-700','dark:bg-gray-700','dark:text-gray-300');
              dot.classList.add('bg-blue-600','text-white');
            } else {
              dot.classList.remove('bg-blue-600','text-white');
              dot.classList.add('bg-gray-200','text-gray-700','dark:bg-gray-700','dark:text-gray-300');
            }
          });
        }

        function showStep(n, { save = true, scroll = true } = {}) {
          n = Math.max(1, Math.min(total, n));
          panels.forEach(p => p.classList.toggle('hidden', p.dataset.step !== String(n)));
          updateDots(n);
          if (mobileBar) mobileBar.style.width = ((n / total) * 100) + '%';
          if (save) localStorage.setItem(`wizard:${storageKey}:step`, String(n));
          if (scroll) stepItems[n-1]?.scrollIntoView({ behavior:'smooth', inline:'center', block:'nearest' });
          root.dataset.currentStep = String(n);
        }

        // Wire controls inside each panel (scoped)
        panels.forEach(panel => {
          const step = +panel.dataset.step;
          const next = panel.querySelector('.btn-next');
          const prev = panel.querySelector('.btn-prev');
          const chk  = panel.querySelector('input[type="checkbox"]');

          chk?.addEventListener('change', () => next?.classList.toggle('hidden', !chk.checked));
          prev?.addEventListener('click', () => showStep(step - 1));
          next?.addEventListener('click', () => {
            const np = root.querySelector(`.step-panel[data-step="${step+1}"]`);
            if (np) {
              const nChk = np.querySelector('input[type="checkbox"]');
              const nBtn = np.querySelector('.btn-next');
              if (nChk) nChk.checked = false;
              if (nBtn) nBtn.classList.add('hidden');
            }
            showStep(step + 1);
          });
        });

        // Persist current step on ANY form submit within this wizard (so a post/redirect/flash returns to same step)
        root.querySelectorAll('form').forEach(f => {
          f.addEventListener('submit', () => {
            const current = root.dataset.currentStep || '1';
            localStorage.setItem(`wizard:${storageKey}:step`, current);
          });
        });

        // Restore last step (persist across reloads)
        const saved = parseInt(localStorage.getItem(`wizard:${storageKey}:step`) || '1', 10);
        showStep(saved, { save: false, scroll: false });
      }

      // Initialize each section independently
      initWizard('auto-wizard',   'auto');   // Automatic Migration
      initWizard('manual-wizard', 'manual'); // Manual Migration
    })();
  </script>
</x-app-layout>
