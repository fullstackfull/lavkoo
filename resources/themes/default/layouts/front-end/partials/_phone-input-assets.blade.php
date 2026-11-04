{{-- intl-tel-input, pulled in only by pages that render a phone field.

     355 KB (the library plus its utils bundle) used to load on every storefront
     page, including the home page, which has no phone input at all.
     intlTelInout-validation.js auto-initialises any input[type="tel"] on
     DOMContentLoaded, so a page opts in by pushing these assets — there is no init
     code to call, and rendering them from a stack keeps the exact script position
     and execution order the layout had. --}}
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/intlTelInput.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/utils.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/intlTelInout-validation.js') }}"></script>
