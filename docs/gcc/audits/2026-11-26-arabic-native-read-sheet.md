# The Arabic strings awaiting a native reader — the sheet to be read

**2026-11-26. Read-only. This sheet decides no wording.** A native Arabic read is a named owner gate (K-13, L-02, L-06,
OWNER_PR_GUIDE), so what follows is the material for the reader and the questions they are being asked, with every string
quoted from the file it lives in. Nothing here applies, changes or proposes a translation.

The sheet exists because K-13's two remaining criteria are not coding tasks: the strings are written and their key parity is
enforced in CI, and what is missing is a person's judgement on record. Until now that material was spread over two
repositories and three pull-request bodies.

## 1. The point-of-sale scan: 12 strings (`lavkooappseller`, `assets/language/{en,ar}.json`)

All twelve are on `main`; eleven arrived with the scan work and the twelfth (`scan_item_or_add_from_item`) predates it and is
included because it is the evidence for the one open wording question.

| key | English | Arabic | where it appears |
|---|---|---|---|
| `scan_shared_code_title` | Several products share this code | هذا الرمز مشترك بين عدة منتجات | `lib/features/pos/widgets/ambiguous_code_sheet_widget.dart` |
| `scan_shared_code_choose_country` | The same code is used in different countries. Tap the product you want to add. | الرمز نفسه مستخدم في دول مختلفة. اضغط على المنتج الذي تريد إضافته. | same sheet |
| `scan_shared_code_open_list` | A scan cannot choose between them. Open your product list and pick the right product. | لا يمكن للمسح أن يختار بينها. افتح قائمة المنتجات واختر المنتج المناسب. | same sheet |
| `scan_open_product_list` | Open product list | فتح قائمة المنتجات | same sheet (the button) |
| `scan_market_unavailable` | Scanning is not available for this market. | المسح غير متاح لهذا السوق. | `lib/features/pos/controllers/barcode_scan_controller.dart` |
| `scan_country_sa` | Saudi Arabia | السعودية | the sheet's per-country rows |
| `scan_country_ae` | UAE | الإمارات | same |
| `scan_country_kw` | Kuwait | الكويت | same |
| `scan_country_qa` | Qatar | قطر | same |
| `scan_country_bh` | Bahrain | البحرين | same |
| `scan_country_om` | Oman | عُمان | same |
| `scan_item_or_add_from_item` | Scan Items or\nAdd From Items | مسح العناصر ضوئيًا أو\nإضافة من العناصر | `lib/features/pos/widgets/pos_no_product_widget.dart` — **predates this work** |

**The open wording question, with the evidence that prompted it.** The app#5 body asked whether المسح should be المسح الضوئي.
The last row is why the question is worth asking rather than guessing: the string this app has shown sellers since before the
GCC work says **مسح … ضوئيًا**, and the three new strings say **المسح** alone. So the screens are not consistent with each
other today, and a reader choosing المسح الضوئي would make them consistent while a reader choosing المسح would make the older
string the odd one out. Both are a decision about the app's own voice, and neither is a correction of an error.

## 2. The two server messages the scan can show (`lavkoo`, `resources/lang/<locale>/new-messages.php`)

The app shows these verbatim, so they are read by a seller even though they are not in the app's own files. Both exist in
every locale file, which `tests/Feature/Catalog/PosScanCodeScopeTest::test_the_messages_of_the_two_refusals_exist_in_every_language_file`
enforces.

| key | `en` | `sa` and `ae` |
|---|---|---|
| `the_scanned_code_matches_more_than_one_product` | The scanned code matches more than one product. Choose one of the listed products, or send the country. | الرمز الممسوح يطابق أكثر من منتج. اختر أحد المنتجات المعروضة أو أرسل الدولة. |
| `the_country_must_be_one_of_the_configured_storefront_countries` | The country must be one of the configured storefront countries. | يجب أن تكون الدولة إحدى دول المتاجر المُعدّة. |

**Two things for the reader to notice.** The `sa` and `ae` texts are **byte-identical**, which is a choice nobody has recorded:
whether Emirati and Saudi sellers should read the same sentence is part of what is being asked. And the second message is
addressed to a *developer* in English ("the configured storefront countries") while a seller is who reads it; whether the
Arabic should say something a seller can act on is a question about the message and not only about its translation.

## 3. The Super Admin shell: 176 strings (`lavkooappseller`, keys beginning `platform_admin`)

No read has been requested for these. L-02 and L-06 both carry a `native-arabic` review and name 43 and 94 keys; the set has
since grown to 176 as the shell gained screens, ten of them on `gcc/development` after `main`
(`platform_admin_app_too_old`, `…_app_too_old_hint`, and the eight `…_stock_*` keys of the market card). They are not
tabulated here because 176 rows is not a sheet anybody reads; the list is produced with

    cd <lavkooappseller> && python3 -c "import json;a=json.load(open('assets/language/ar.json'));e=json.load(open('assets/language/en.json'));[print(k,'|',e[k],'|',a[k]) for k in sorted(a) if k.startswith('platform_admin')]"

**One of the 176 contains Latin letters, and it is deliberate**: `platform_admin_mfa_subtitle_recovery` is
«أدخل أحد رموز الاسترداد، مثل abcde-fghij. كل رمز يُستخدم مرة واحدة فقط.» — the Latin run is an example of a recovery code's
shape, which is Latin in the product. It is called out because the ratchet that counts Latin letters in an Arabic file exists
precisely to catch English left in a translation, and a reader should be told which one is an exception rather than discovering
it and wondering. Every other one of the 176 is Arabic throughout.

## 4. What the reader is asked to answer

1. **المسح or المسح الضوئي** in the three new scan strings, given that the older string uses مسح … ضوئيًا (section 1).
2. Any correction to the other nine scan strings and the six country names — in particular **عُمان** carrying its damma, which
   the other five names do not carry diacritics.
3. The two server messages: the wording, and whether `sa` and `ae` should stay identical (section 2).
4. Whether the second server message should be rewritten for a seller rather than translated as it stands.
5. Whether the 176 Super Admin strings are to be read at all, and if so in one pass or per screen.

## 5. What this sheet does NOT contain, and why

**No RTL screenshots.** K-13's next task asks for the candidate sheet rendered in RTL at 320×640 and at 2× text. There is no
Flutter toolchain and no device in the container this was written in, so no screen of this app was rendered or photographed
here, and a sheet claiming otherwise would be worthless to the reader. The screenshots need a session with a toolchain or a
device, and that is the one part of the preparation still outstanding.

**No wording applied.** `assets/language/ar.json` and `resources/lang/{sa,ae}/new-messages.php` are untouched by this sheet.
When the reader answers, the answer goes in as one commit per repository, and K-13's two criteria move then — not now.
