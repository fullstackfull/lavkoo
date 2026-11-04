@extends('layouts.admin.app')

@section('title', translate('general_Settings'))

@push('css_or_js')
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/component-snippets.css') }}">
<link href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/highlight/highlight-default.min.css') }}" rel="stylesheet" />
@endpush

@section('content')

    <div class="container-fluid">
        <h2 class="text-primary mb-3">Dropdown</h2>
        <div class="d-flex flex-column gap-4">

            <div class="card mb-3 p-4">
                <div class="component-snippets-container">
                    <div class="component-snippets-preview">
                        <div id="liveAlertPlaceholder">
                            <div></div>
                        </div>
                        <button type="button" class="btn btn-primary" id="liveAlertBtn">Show live alert</button>
                    </div>

                    <div class="component-snippets-code-header">
                        <ul class="nav nav-tabs" id="myTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#home-tab-pane" type="button" role="tab" aria-controls="home-tab-pane" aria-selected="true">Html</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-tab-pane" type="button" role="tab" aria-controls="profile-tab-pane" aria-selected="false">CSS</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-tab-pane" type="button" role="tab" aria-controls="contact-tab-pane" aria-selected="false">JavaScript</button>
                            </li>
                        </ul>
                        <button class="btn btn-icon copy-button">
                            <i class="fi fi-rr-copy"></i>
                        </button>
                    </div>

                    <div class="tab-content" id="myTabContent">
                        <div class="tab-pane fade show active" id="home-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
                            <div class="component-snippets-code-container">
<pre><code><div id="liveAlertPlaceholder">
    <div></div>
</div>
<button type="button" class="btn btn-primary" id="liveAlertBtn">Show live alert</button>
</code></pre>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="profile-tab-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
                            <div class="component-snippets-code-container">
<pre><code>.component-snippets-code-header {

}

.component-snippets-code-container {
    background: #f8f9fa;
    padding: 0;
}

.component-snippets-code-container pre {
    margin: 0 !important;
}
</code></pre>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="contact-tab-pane" role="tabpanel" aria-labelledby="contact-tab" tabindex="0">
                            <div class="component-snippets-code-container">
<pre><code>import { marked } from "https://cdn.jsdelivr.net/npm/marked/lib/marked.esm.js";
  document.getElementById('content').innerHTML =
    marked.parse('# Marked in the browser\n\nRendered by **marked**.');</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


        </div>
    </div>
    </div>

@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/highlight/highlight.min.js') }}"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            hljs.initHighlightingOnLoad();
            document.querySelectorAll(".component-snippets-code-container code").forEach((block) => {
                block.textContent = block.innerHTML.trim();
            });

            // Add copy functionality
            document.querySelectorAll('.copy-button').forEach(button => {
                button.addEventListener('click', () => {
                    const codeBlock = button.closest('.component-snippets-container').querySelector('.tab-pane.active code');
                    navigator.clipboard.writeText(codeBlock.textContent).then(() => {
                        // Change icon temporarily to show success
                        const icon = button.querySelector('i');
                        icon.classList.remove('fi-rr-copy');
                        icon.classList.add('fi-rr-check');

                        setTimeout(() => {
                            icon.classList.remove('fi-rr-check');
                            icon.classList.add('fi-rr-copy');
                        }, 1000);
                    });
                });
            });
        });
    </script>
@endpush
