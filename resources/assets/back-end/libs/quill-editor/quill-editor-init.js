const BlockEmbed = Quill.import('blots/block/embed');

class CustomVideo extends BlockEmbed {
    static create(value) {
        const node = super.create();

        node.setAttribute('src', value);
        node.setAttribute('frameborder', '0');
        node.setAttribute('allowfullscreen', true);

        // YOUR REQUIRED ATTRIBUTES
        node.setAttribute(
            'allow',
            'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
        );
        node.setAttribute(
            'referrerpolicy',
            'strict-origin-when-cross-origin'
        );
        node.setAttribute('title', 'YouTube video player');

        node.setAttribute('width', '560');
        node.setAttribute('height', '315');

        return node;
    }

    static value(node) {
        return node.getAttribute('src');
    }
}

CustomVideo.blotName = 'customVideo';
CustomVideo.tagName = 'iframe';

Quill.register(CustomVideo);

// --- Custom Table Icons (Self-Contained) ---
const customTableIcons = {
    'table': '<svg viewBox="0 0 18 18"><rect class="ql-stroke" height="12" width="15" x="1.5" y="3"></rect><line class="ql-stroke" x1="1.5" x2="16.5" y1="9" y2="9"></line><line class="ql-stroke" x1="6.5" x2="6.5" y1="3" y2="15"></line><line class="ql-stroke" x1="11.5" x2="11.5" y1="3" y2="15"></line></svg>',
    'insert-row-below': '<svg viewBox="0 0 18 18"><rect class="ql-stroke" height="12" width="15" x="1.5" y="3"></rect><line class="ql-stroke" x1="1.5" x2="16.5" y1="9" y2="9"></line><path class="ql-fill" d="M9,13l-3-3h6L9,13z"></path></svg>',
    'insert-column-right': '<svg viewBox="0 0 18 18"><rect class="ql-stroke" height="12" width="15" x="1.5" y="3"></rect><line class="ql-stroke" x1="9" x2="9" y1="3" y2="15"></line><path class="ql-fill" d="M13,9l-3-3v6L13,9z"></path></svg>',
    'delete-row': '<svg viewBox="0 0 18 18"><rect class="ql-stroke" height="12" width="15" x="1.5" y="3"></rect><line class="ql-stroke" x1="5" x2="13" y1="12" y2="12"></line><line class="ql-stroke" x1="1.5" x2="16.5" y1="9" y2="9"></line></svg>',
    'delete-column': '<svg viewBox="0 0 18 18"><rect class="ql-stroke" height="12" width="15" x="1.5" y="3"></rect><line class="ql-stroke" x1="9" x2="9" y1="3" y2="15"></line><line class="ql-stroke" x1="12" x2="12" y1="6" y2="12"></line></svg>',
    'delete-table': '<svg viewBox="0 0 18 18"><rect class="ql-stroke" height="12" width="15" x="1.5" y="3"></rect><line class="ql-stroke" x1="3" x2="15" y1="3" y2="15"></line><line class="ql-stroke" x1="15" x2="3" y1="3" y2="15"></line></svg>'
};

// --- Injected Premium Table Styles ---
if (!document.getElementById('ql-table-custom-styles')) {
    const tableStyleInjected = document.createElement('style');
    tableStyleInjected.id = 'ql-table-custom-styles';
    tableStyleInjected.innerHTML = `
        .ql-editor table { border-collapse: collapse; width: 100%; margin: 15px 0; table-layout: fixed; }
        .ql-editor table td { border: 1px solid #e1e1e1; padding: 12px; min-width: 40px; position: relative; }
        .ql-editor table td:hover { background-color: #f8faff; outline: 1px solid #3b71fe; z-index: 1; }
        .ql-editor table tr:nth-child(even) { background-color: #fafafa; }
        .ql-container.ql-snow { font-size: 14px; border-radius: 0 0 8px 8px; }
        .ql-toolbar.ql-snow { border-radius: 8px 8px 0 0; background-color: #fdfdfd; }
    `;
    document.head.appendChild(tableStyleInjected);
}

$(document).ready(function () {

    $('.quill-editor').each(function (index) {
        var associatedTextarea = $(this).siblings('textarea');
        var container = this;

        var toolbarOptions = [

            [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'header': 1 }, { 'header': 2 }],
            [{ 'list': 'ordered' }, { 'list': 'bullet' }, { 'list': 'check' }],
            [{ 'align': [] }],
            [{ 'font': [] }],
            [{ 'size': ['small', false, 'large', 'huge'] }],
            [{ 'color': [] }, { 'background': [] }],
            ['blockquote', 'code-block'],
            ['link', 'image', 'video', 'formula'],
            [{ 'script': 'sub' }, { 'script': 'super' }],
            [{ 'indent': '-1' }, { 'indent': '+1' }],
            [{ 'direction': 'rtl' }],
            ['table', 'insert-row-below', 'insert-column-right', 'delete-row', 'delete-column', 'delete-table']
        ];

        var quillEditor = new Quill(this, {
            theme: 'snow',
            modules: {
                table: true,
                toolbar: {
                    container: toolbarOptions,
                    handlers: {
                        'table': function() { this.quill.getModule('table').insertTable(2, 2); },
                        'insert-row-below': function() { this.quill.getModule('table').insertRowBelow(); },
                        'insert-column-right': function() { this.quill.getModule('table').insertColumnRight(); },
                        'delete-row': function() { this.quill.getModule('table').deleteRow(); },
                        'delete-column': function() { this.quill.getModule('table').deleteColumn(); },
                        'delete-table': function() { this.quill.getModule('table').deleteTable(); },
                        image: function () {
                            var range = this.quill.getSelection();
                            var input = document.createElement('input');
                            input.setAttribute('type', 'file');
                            input.setAttribute('accept', 'image/*');
                            input.click();
                            input.onchange = () => {
                                var file = input.files[0];
                                if (file) {
                                    var reader = new FileReader();
                                    reader.onload = () => {
                                        var base64Image = reader.result;
                                        // Open the modal to ask for alt text
                                        openAltTextModal(base64Image, range.index, this.quill, 'image');
                                    };
                                    reader.readAsDataURL(file);
                                }
                            };
                        },
                        video: function () {
                            var range = this.quill.getSelection();
                            openVideoModal(range.index, this.quill);
                        }
                    }
                }
            }
        });

        // Hydrate initial content from textarea to ensure table module recognizes it
        var initialHTML = associatedTextarea.val();
        if (initialHTML) {
            quillEditor.root.innerHTML = initialHTML;
        }

        // Set the custom icons for table actions
        const toolbar = quillEditor.getModule('toolbar');
        Object.keys(customTableIcons).forEach(key => {
            const btn = toolbar.container.querySelector(`.ql-${key}`);
            if (btn) btn.innerHTML = customTableIcons[key];
        });

        quillEditor.on('text-change', function () {
            associatedTextarea.val(quillEditor.root.innerHTML);
        });

        // Store the Quill instance on the element for later use
        $(this).data('quill', quillEditor);
    });

// Function to open the alt text modal for image
    function openAltTextModal(mediaSource, index, quill, type) {
        var modalHTML = `
            <div class="alt-text-modal-overlay">
                <div class="alt-text-modal">
                    <h2>Enter Alt Text for the ${type === 'image' ? 'Image' : 'Video'}</h2>
                    <input type="text" id="alt-text-input" placeholder="Enter alt text...">
                    <button id="save-alt-text-btn">Save</button>
                    <button id="cancel-alt-text-btn">Cancel</button>
                </div>
            </div>
        `;

        // Append modal to the body
        $('body').append(modalHTML);

        // Save button click event
        $('#save-alt-text-btn').on('click', function () {
            var altText = $('#alt-text-input').val();
            if (type === 'image') {
                quill.insertEmbed(index, 'image', mediaSource, Quill.sources.USER);
                var img = quill.root.querySelector(`img[src="${mediaSource}"]`);
                if (img) {
                    img.setAttribute('alt', altText); // Set alt text
                }
            } else if (type === 'video') {
                var videoUrl = mediaSource;
                var iframe = `https://www.youtube.com/embed/${videoUrl}`;
                quill.insertEmbed(index, 'video', iframe, Quill.sources.USER);
                var iframeElement = quill.root.querySelector(`iframe[src="https://www.youtube.com/embed/${videoUrl}"]`);
                if (iframeElement) {
                    iframeElement.setAttribute('alt', altText || ''); // Set alt text for video
                }
            }
            closeAltTextModal();
        });

        // Cancel button click event
        $('#cancel-alt-text-btn').on('click', function () {
            closeAltTextModal();
        });
    }

// Function to close the modal
    function closeAltTextModal() {
        $('.alt-text-modal-overlay').remove();
    }

// Open YouTube video modal
    function openVideoModal(index, quill) {
        var modalHTML = `
            <div class="video-url-modal-overlay">
                <div class="video-url-modal">
                    <h2>Enter YouTube Video URL</h2>
                    <input type="text" id="video-url-input" placeholder="Enter YouTube URL...">
                    <button id="save-video-url-btn">Save</button>
                    <button id="cancel-video-url-btn">Cancel</button>
                </div>
            </div>
        `;

        // Append modal to the body
        $('body').append(modalHTML);

        // Save button click event for video
        $('#save-video-url-btn').on('click', function () {
            var videoUrl = $('#video-url-input').val();
            var videoId = extractYouTubeID(videoUrl);
            if (videoId) {
                // Create the iframe element
                var iframeElement = `https://www.youtube.com/embed/${videoId}`;
                // Insert the video into the Quill editor
                quill.insertEmbed(index, 'customVideo', iframeElement, Quill.sources.USER);
            } else {
                alert('Invalid YouTube URL');
            }
            closeVideoModal();
        });

        // Cancel button click event
        $('#cancel-video-url-btn').on('click', function () {
            closeVideoModal();
        });
    }


    // Function to close the video modal
    function closeVideoModal() {
        $('.video-url-modal-overlay').remove();
    }

    // Function to extract YouTube video ID from URL
    function extractYouTubeID(url) {
        var match = url.match(/(?:youtube\.com\/(?:[^/]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        return match ? match[1] : null;
    }

});
