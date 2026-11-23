# The upload surface audit: what was mapped and where each suspect went (2026-11-16)

Seven areas of `fullstackfull/lavkoo` were mapped by a reader that executed each suspect in a scratch tree (workflow `wf_4bd8e714-ec2`; its journal is not durable, so the table is kept here): 146 suspects. A second reader then re-runs each suspect against the newest head of draft PR #63 and tries to refute it (`probe` phase); at the time of writing 3 probes had returned, all "not reproduced" at the head they ran on:

- `v1-ai-analyze-image`: reproduced = False
- `v1-digital-otp-verify`: reproduced = False
- `v1-chat-send-message-file`: reproduced = False

**How to read the disposition column.** "#63 section N" is the section of the PR body of [#63](https://github.com/fullstackfull/lavkoo/pull/63) that closes it, with red/green tests. A suspect whose disposition names the central helper sections (1, 2, 3) is closed by the shared rule every writer uses, not by a fix of its own handler; the first two probes confirm that rule holds on the paths they ran. "Owner decision" means the behaviour is by design or needs a product decision, and is listed in the PR body. The disposition of each row was assigned by the route and the kind of risk, not by re-running that row.

Risk flags: **E** a script engine may run the stored file, **S** a browser may run it as a page, **P** a stored name or path reaches other files (a letter means likely or possible, "-" none).

## Customer app API v1 (15)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `v1-ai-analyze-image` | POST /api/v1/customer/auction/product/analyze-image-auto-fill (declared in Modules/AI/routes/api.php:48-57: gr... | customer | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `v1-digital-otp-verify` | GET /api/v1/customer/order/digital-product-download-otp-verify?guest_id=1&otp=NNNN&order_details_id=N&action=d... | anonymous | --P | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `v1-chat-send-message-file` | POST /api/v1/customer/chat/send-message/{type} (type seller/delivery-man, id=0 means the admin); multipart fie... | customer | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `v1-chat-send-message-media` | POST /api/v1/customer/chat/send-message/{type}; multipart field `media[]` | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `v1-support-ticket-reply` | POST /api/v1/customer/support-ticket/reply/{ticket_id}; multipart field `image[]` | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `v1-review-submit` | POST /api/v1/products/reviews/submit; multipart field `fileUpload[]` (the app sends fileUpload[0], fileUpload[... | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `v1-review-update` | PUT /api/v1/products/review/update (the app posts with _method=put); multipart field `fileUpload[]` | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `v1-digital-download-by-id` | GET /api/v1/customer/order/digital-product-download/{id} (middleware apiGuestCheck; {id} = order_details id) | anonymous | --P | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `v1-chat-get-messages` | GET /api/v1/customer/chat/get-messages/{type}/{id} | customer | --- | Checked: no finding |
| `v1-support-ticket-create` | POST /api/v1/customer/support-ticket/create; multipart field `image[]` | customer | --- | Checked: no finding |
| `v1-refund-store` | POST /api/v1/customer/order/refund-store; multipart field `images[]` | customer | --- | Checked: no finding |
| `v1-profile-update` | PUT /api/v1/customer/update-profile (the app posts with _method=put); multipart field `image` | customer | --- | Checked: no finding (the image goes through the helpers of #63 sections 1 and 2) |
| `v1-review-delete-image` | DELETE /api/v1/products/review/delete-image (id, name) | customer | --- | #63 section 3 (delete guards: nothing is deleted when the name goes up) |
| `v1-account-delete` | GET /api/v1/customer/account-delete/{id} | customer | --- | Checked: no finding |
| `v1-lang-header` | every /api/v1/* route (group middleware api_lang), request header `lang` | anonymous | --- | Checked: no finding |

## Seller and delivery-man APIs v2/v3 (28)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `sellerapi-v3-products-add-names` | POST /api/v3/seller/products/add (string fields digital_file_ready, images, thumbnail, color_image: names of e... | seller_staff | --P | #63 sections 3 and 5 (a stored name is one plain segment) |
| `sellerapi-v3-products-add-preview-file` | POST /api/v3/seller/products/add (file field preview_file, product_type=digital) | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-upload-digital-product` | POST /api/v3/seller/products/upload-digital-product (file field digital_file_ready) | seller_staff | ES- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `sellerapi-v3-products-add-digital-files` | POST /api/v3/seller/products/add (file fields digital_files_<variant key>, e.g. digital_files_format_pdf, prod... | seller_staff | ES- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `sellerapi-v3-products-update-preview-file` | PUT /api/v3/seller/products/update/{id} (multipart with _method=PUT; file field preview_file) | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-products-update-digital-files` | PUT /api/v3/seller/products/update/{id} (file fields digital_files_<variant key>) | seller_staff | ES- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `sellerapi-v3-products-update-names` | PUT /api/v3/seller/products/update/{id} (string fields images[].image_name, color_image, thumbnail, digital_fi... | seller_staff | --P | #63 sections 3 and 5 (a stored name is one plain segment) |
| `sellerapi-v2-products-add-names` | POST /api/v2/seller/products/add (string fields images[], thumbnail, digital_file_ready, meta_image) | seller | --P | #63 sections 3 and 5 (a stored name is one plain segment) |
| `sellerapi-v2-products-update-names` | PUT /api/v2/seller/products/update/{id} (string fields images, thumbnail, digital_file_ready, meta_image) | seller | --P | #63 sections 3 and 5 (a stored name is one plain segment) |
| `sellerapi-v3-shop-update-tin-certificate` | PUT /api/v3/seller/shop-update (multipart with _method=PUT; file field tin_certificate) | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-ai-analyze-image` | POST /api/v3/seller/product/analyze-image-auto-fill (file field image) - registered in Modules/AI/routes/api.p... | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-ai-auction-analyze-image` | POST /api/v3/seller/auction/product/analyze-image-auto-fill (file field image) - Modules/AI/routes/api.php | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v2-registration-images` | POST /api/v2/seller/registration (file fields image, logo, banner) | anonymous | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-registration-tin-certificate` | POST /api/v3/seller/registration (file field tin_certificate; image, logo, banner, bottom_banner are safe) | anonymous | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-products-delete-image` | GET /api/v3/seller/products/delete-image?id=&name=&color= | seller_staff | --P | #63 sections 3 and 15 (deletes only names the product holds) |
| `sellerapi-v3-products-delete` | DELETE /api/v3/seller/products/delete/{id} | seller_staff | --P | #63 section 3 (delete guards) |
| `sellerapi-v2-products-delete` | DELETE /api/v2/seller/products/delete/{id} | seller | --P | #63 section 3 (delete guards) |
| `sellerapi-v2-dm-chat-media` | POST /api/v2/delivery-man/messages/send-message/{type} (media[]; type seller/customer/admin) | delivery_man | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-chat-media` | POST /api/v3/seller/messages/send/{type} (media[]; type customer/delivery-man) | seller_staff | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v2-dm-chat-file` | POST /api/v2/delivery-man/messages/send-message/{type} (file[]; type seller/customer/admin) | delivery_man | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-chat-file` | POST /api/v3/seller/messages/send/{type} (file[]) | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v2-shop-update-image` | PUT /api/v2/seller/shop-update (multipart with _method=PUT; file field image) | seller | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v2-seller-update-image` | PUT /api/v2/seller/seller-update (multipart with _method=PUT; file field image) | seller | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-delivery-man-store-image` | POST /api/v3/seller/delivery-man/store (file field image; identity_image[] is validated) | seller_staff | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-delivery-man-update-images` | PUT /api/v3/seller/delivery-man/update/{id} (file fields image, identity_image[]) | seller_staff | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v3-after-sale-upload` | PUT /api/v3/seller/orders/order-wise-product-upload (multipart with _method=PUT; file field digital_file_after... | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v2-after-sale-upload` | PUT /api/v2/seller/orders/order-wise-product-upload (file field digital_file_after_sell, order_id) | seller | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `sellerapi-v2-upload-digital-product` | POST /api/v2/seller/products/upload-digital-product (file field digital_file_ready) | seller | ES- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |

## Website for shoppers (15)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `web-chat-file` | POST /message (ChattingController@addMessage), multipart field file[] | customer | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `web-image-proxy` | GET /image-proxy?url= (closure, throttle:30,1) | anonymous | -S- | #63 section 4 |
| `web-chat-media` | POST /message (ChattingController@addMessage), multipart field media[] | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `web-review-attachments` | POST /review (review.store), multipart field fileUpload[] | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `web-refund-images` | POST /refund-store (refund-store), multipart field images[] | customer | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `web-ai-analyze-image` | POST /customer/auction/product/analyze-image-auto-fill (Modules/AI/routes/web.php, middleware customer) | customer | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `web-ticket-submit` | POST /ticket-submit (ticket-submit), multipart field image[] | anonymous | --- | Checked: no finding |
| `web-digital-download-otp` | GET /digital-product-download/{id}; POST /digital-product-download-otp-reset; POST /digital-product-download-o... | anonymous | --- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `web-ticket-comment` | POST /support-ticket/{id} (support-ticket.comment), multipart field image[] | customer | --- | Checked: no finding |
| `web-profile-image` | POST /user-account-update (user-update), multipart field image | customer | --- | Checked: no finding |
| `web-review-delete-image` | POST /review-delete-image (delete-review-image) | customer | --- | #63 section 3 (delete guards: nothing is deleted when the name goes up) |
| `web-stored-invoice-pdf` | OrderManager::storeInvoice -> PdfGenerator::storePdf (not routed; runs at order placement when mail is configu... | internal | --- | Checked: no finding |
| `web-support-ticket-delete` | GET /support-ticket/delete/{id} (support-ticket.delete) | customer | --- | Checked: no finding |
| `web-account-delete` | GET /account-delete/{id} (account-delete) | customer | --- | Checked: no finding |
| `web-offline-payment` | POST /offline-payment-checkout-complete (offline-payment-checkout-complete) | anonymous | --- | Checked: no finding |

## Vendor (seller) web panel (18)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `vendor-registration-tin-certificate` | POST /vendor/auth/registration/add (vendor.auth.registration.add; middleware web, maintenance_mode, actch:admi... | anonymous | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-product-add` | POST /vendor/products/add (vendor.products.add) | seller_staff | ESP | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-product-update` | POST /vendor/products/update/{id} (vendor.products.update) | seller_staff | ESP | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-chat-add-message` | POST /vendor/messages/message (same URI as the GET named vendor.messages.message; fields user_id or delivery_m... | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-shop-other-settings-tin` | POST /vendor/shop/update-other-settings (vendor.shop.update-other-settings) | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-order-after-sale-file` | POST /vendor/orders/digital-file-upload-after-sell (vendor.orders.digital-file-upload-after-sell; field digita... | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-product-delete-image` | GET /vendor/products/delete-image?id=<product>&image=<name>&name=<x> (vendor.products.delete-image; a state-ch... | seller_staff | --P | #63 sections 3 and 15 (deletes only names the product holds) |
| `vendor-shop-update` | POST /vendor/shop/update/{id} (vendor.shop.update; the edit form only offers image and banner) | seller_staff | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-ai-product-analyze-image` | POST /vendor/product/analyze-image-auto-fill (vendor.product.analyze-image-auto-fill; module route group has m... | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-ai-auction-analyze-image` | POST /vendor/auction/product/analyze-image-auto-fill (vendor.auction.product.analyze-image-auto-fill; middlewa... | seller_staff | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-product-update-images` | POST /vendor/products/update-product-images/{id} (vendor.products.update-product-images; fields images[], imag... | seller_staff | -SP | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-delivery-man-update` | POST /vendor/delivery-man/update/{id} (vendor.delivery-man.update; fields image, identity_image[]) | seller_staff | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-delivery-man-add` | POST /vendor/delivery-man/index (vendor.delivery-man.index; fields image, identity_image[]) | seller_staff | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `vendor-product-delete-preview-file` | POST /vendor/products/delete-preview-file (vendor.products.delete-preview-file; field product_id) | seller_staff | --P | #63 section 3 (delete guards) |
| `vendor-product-delete` | DELETE /vendor/products/delete/{id} (vendor.products.delete) | seller_staff | --P | #63 section 3 (delete guards) |
| `vendor-product-bulk-import` | POST /vendor/products/bulk-import (vendor.products.bulk-import; field products_file) | seller_staff | --P | #63 section 5 (the sheet cell is one plain name) |
| `vendor-product-digital-variation-file-delete` | POST /vendor/products/digital-variation-file-delete (vendor.products.digital-variation-file-delete; fields pro... | seller_staff | --- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `vendor-product-digital-variation-combination` | POST /vendor/products/digital-variation-combination (vendor.products.digital-variation-combination; fields pro... | seller_staff | --- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |

## Admin web panel (33)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `admin-addon-upload` | POST /admin/addon/upload ; POST /admin/system-setup/addon/upload | admin_employee | ES- | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `admin-addon-delete` | POST /admin/addon/delete ; POST /admin/system-setup/addon/delete | admin_employee | --P | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `admin-addon-publish-activation` | POST /admin/addon/publish ; POST /admin/addon/activation ; POST /admin/system-setup/addon/publish ; POST /admi... | admin_employee | E-P | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `admin-sitemap-download` | GET /admin/seo-settings/sitemap-download?path=<base64> | admin_employee | --P | #63 section 7 |
| `admin-chat-file` | POST /admin/messages/message (file[]) | admin_employee | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-profile-update` | POST /admin/profile/update/{id} | admin_employee | -S- | #57 (ownership) and #63 section 2 (the image) |
| `admin-ai-analyze-image` | POST /admin/product/analyze-image-auto-fill ; POST /admin/blog/analyze-image-auto-fill ; POST /admin/auction/p... | admin_employee | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-filemanager-zip` | POST /admin/system-setup/file-manager/image-upload (file=zip, path=...) | admin_employee | ESP | #63 sections 8 and 14 |
| `admin-sitemap-delete` | GET /admin/seo-settings/sitemap-delete?path=<base64> | admin_employee | --P | #63 section 7 |
| `admin-app-carousel-update` | POST /admin/app-carousel/update | admin_employee | ES- | #63 section 16 |
| `admin-digital-file-after-sell` | POST /admin/orders/digital-file-upload-after-sell | admin_employee | ES- | #63 section 3 (paid order, plain names, lock after five wrong codes) and section 1 |
| `admin-product-add-update-files` | POST /admin/products/add ; POST /admin/products/update/{id} | admin_employee | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-vendor-add-tin-certificate` | POST /admin/vendors/add (tin_certificate) | admin_employee | ES- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-filemanager-images` | POST /admin/system-setup/file-manager/image-upload (images[], path=...) | admin_employee | ESP | #63 sections 8 and 14 |
| `admin-chat-media` | POST /admin/messages/message (media[]) | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-filemanager-download-index` | GET /admin/system-setup/file-manager/download/{file_name}?storage=public ; GET /admin/system-setup/file-manage... | admin_employee | --P | #63 section 14 |
| `admin-language-add` | POST /admin/system-setup/language/add | admin_employee | E-P | #63 sections 9 and 12 |
| `admin-software-update` | POST /admin/system-setup/software-update | admin_employee | ES- | Owner decision: a code update by design; the licence call fails open |
| `admin-theme-asset-upload` | POST /admin/theme/asset/upload ; POST /admin/theme/builder/media/upload | admin_employee | -S- | #63 section 11 |
| `admin-product-images-svg` | POST /admin/products/add ; POST /admin/products/update/{id} ; POST /admin/products/update-product-images/{id} | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-product-delete-image` | GET /admin/products/delete-image?id=&name=&image= | admin_employee | --P | #63 sections 3 and 15 (deletes only names the product holds) |
| `admin-blog-download-app-and-delete-image` | POST /admin/blog/app-download-setup (icon, image) ; POST /admin/blog/delete-image (icon, image) | admin_employee | -SP | #63 section 18 |
| `admin-seo-meta-image` | POST /admin/brand/add-new ; POST /admin/brand/update/{id} ; POST /admin/category/add-new ; POST /admin/categor... | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-most-demanded` | POST /admin/most-demanded/store ; POST /admin/most-demanded/update/{id} | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-sitemap-manual-upload` | POST /admin/seo-settings/sitemap-manual-upload (xml_file) | admin_employee | -S- | #63 section 7 |
| `admin-delivery-man-identity-image` | POST /admin/delivery-man/add ; POST /admin/delivery-man/update/{id} | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-inhouse-shop-logo` | POST /admin/business-settings/inhouse-shop (image) | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-features-section-icon` | POST /admin/pages-and-media/features-section/submit (features_section_bottom_icon[]) | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-email-template-images` | POST /admin/system-setup/email-templates/update/{template}/{user_type} (image, icon, logo, banner_image) | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-firebase-service-worker` | POST /admin/third-party/firebase-configuration/setup | admin_employee | -S- | #63 section 10 |
| `admin-product-existing-names` | POST /admin/products/add (existing_thumbnail, existing_images[], existing_meta_image, existing_preview_file) t... | admin_employee | --P | #63 sections 3 and 5 (a stored name is one plain segment) |
| `admin-blog-add-update-meta-image` | POST /admin/blog/add ; POST /admin/blog/update | admin_employee | -S- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `admin-language-delete-and-translate` | GET /admin/system-setup/language/delete/{lang} ; GET /admin/system-setup/language/translate/{lang} ; POST /adm... | admin_employee | --P | #63 sections 9 and 12 |

## Modules and services (AI, Blog, TaxModule, services, traits, exports) (29)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `ms-filemanager-upload` | POST /admin/system-setup/file-manager/image-upload | admin_employee | ESP | #63 sections 8 and 14 |
| `ms-addon-upload` | POST /admin/addon/upload (same handler on POST /admin/system-setup/addon/upload, which adds module:themes_and_... | admin_employee | ES- | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `ms-gateway-image-read` | PUT /admin/third-party/payment-method/addon-payment-set | admin_employee | --P | #63 section 13 |
| `ms-sitemap-download` | GET /admin/seo-settings/sitemap-download?path=<base64> | admin_employee | --P | #63 section 7 |
| `ms-addon-publish` | POST /admin/addon/publish (same handler on POST /admin/system-setup/addon/publish, which adds module:themes_an... | admin_employee | E-P | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `ms-addon-delete` | POST /admin/addon/delete (same handler on POST /admin/system-setup/addon/delete, which adds module:themes_and_... | admin_employee | --P | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `ms-language-autotranslate` | ANY /admin/system-setup/language/auto-translate-all/{lang} (the value is planted through POST /admin/system-se... | admin_employee | E-- | #63 sections 9 and 12 |
| `ms-language-add` | POST /admin/system-setup/language/add | admin_employee | E-P | #63 sections 9 and 12 |
| `ms-addon-activation` | POST /admin/addon/activation (same handler on POST /admin/system-setup/addon/activation, which adds module:the... | admin_employee | E-P | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `ms-software-update` | POST /admin/system-setup/software-update | admin_employee | ES- | Owner decision: a code update by design; the licence call fails open |
| `ms-sitemap-delete` | GET /admin/seo-settings/sitemap-delete?path=<base64> | admin_employee | --P | #63 section 7 |
| `ms-theme-asset-upload` | POST /admin/theme/asset/upload | admin_employee | -S- | #63 section 11 |
| `ms-theme-builder-media-upload` | POST /admin/theme/builder/media/upload | admin_employee | -S- | #63 section 11 |
| `ms-sitemap-upload` | POST /admin/seo-settings/sitemap-manual-upload | admin_employee | -S- | #63 section 7 |
| `ms-language-delete` | GET /admin/system-setup/language/delete/{lang} | admin_employee | --P | #63 sections 9 and 12 |
| `ms-filemanager-download` | GET /admin/system-setup/file-manager/download/{file_name} | admin_employee | --P | #63 section 14 |
| `ms-filemanager-index` | GET /admin/system-setup/file-manager/index?storage=<disk>&targetFolder=<base64> | admin_employee | --P | #63 sections 8 and 14 |
| `ms-vendor-product-delete-image` | GET /vendor/products/delete-image (same code at GET /admin/products/delete-image) | seller | --P | #63 sections 3 and 15 (deletes only names the product holds) |
| `ms-product-existing-names` | POST /vendor/products/add (same existing_* names on POST /vendor/products/update/{id}, POST /admin/products/ad... | seller | --P | #63 sections 3 and 5 (a stored name is one plain segment) |
| `ms-blog-delete-image` | POST /admin/blog/delete-image | admin_employee | --P | #63 section 18 |
| `ms-ai-auction-image` | POST /customer/auction/product/analyze-image-auto-fill (web) and POST /api/v1/customer/auction/product/analyze... | customer | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-helper-delete-by-name` | FileManagerTrait::delete / ::update and ImageManager::delete / ::update (helpers, called by many controllers) | internal | --P | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-ai-seller-api-product-image` | POST /api/v3/seller/product/analyze-image-auto-fill | seller_staff | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-ai-vendor-product-image` | POST /vendor/product/analyze-image-auto-fill | seller_staff | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-ai-vendor-auction-image` | POST /vendor/auction/product/analyze-image-auto-fill | seller_staff | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-ai-admin-product-image` | POST /admin/product/analyze-image-auto-fill | admin_employee | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-ai-admin-blog-image` | POST /admin/blog/analyze-image-auto-fill | admin_employee | E-- | #63 sections 1 and 2 (every writer stores a data extension; an SVG is never stored as an image) |
| `ms-firebase-sw` | POST /admin/third-party/firebase-configuration/setup | admin_employee | -S- | #63 section 10 |
| `ms-apple-key` | POST /admin/third-party/social-login/update-apple/{service} | admin_employee | --- | Owner decision: the signing key sits on the public disk under a time-based name |

## Central helpers (ImageManager, FileManagerTrait, Processor, StoredFileExtension, raw writers) (8)

| Suspect | Route | Actor | E S P | Disposition |
|---|---|---|---|---|
| `files-software-update` | POST /admin/system-setup/software-update [module:system_settings] | admin_employee | ES- | Owner decision: a code update by design; the licence call fails open |
| `files-addon-upload` | POST /admin/addon/upload and POST /admin/system-setup/addon/upload [module:themes_and_addons] | admin_employee | ES- | #63 section 6 (permission and path); a permission holder installs PHP by design (owner decision) |
| `files-filemanager-download-index` | GET /admin/system-setup/file-manager/download/{file_name}?storage= and GET /admin/system-setup/file-manager/in... | admin_employee | --P | #63 section 14 |
| `files-seller-delete-image` | GET /api/v3/seller/products/delete-image?id=&color=&name= [seller_api_auth, seller_can:products.manage]; same ... | seller | --P | #63 sections 3 and 15 (deletes only names the product holds) |
| `files-app-carousel-upload` | POST /admin/app-carousel/update, field images[] [module:marketing] | admin_employee | ES- | #63 section 16 |
| `files-env-writer` | POST /admin/system-setup/environment-setup and POST /admin/system-setup/environment-update-force-https [module... | admin_employee | --- | #63 section 17 |
| `files-paymera-callback-log` | GET/POST/PUT/PATCH/DELETE/OPTIONS /payment/paymera/callback (CSRF-exempt) | anonymous | --- | Draft PR #7 (stops logging the callback) |
| `files-admin-blog-delete-image` | GET /admin/products/delete-image?id=&image= [module:catalog]; POST /admin/blog/delete-image (icon, image) [mod... | admin_employee | --P | #63 section 18 |

