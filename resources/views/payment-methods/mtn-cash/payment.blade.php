<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>الدفع عبر MTN Cash</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Tahoma, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .wrapper {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: #ffffff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
        }

        .logo {
            text-align: center;
            margin-bottom: 18px;
        }

        .logo-title {
            display: inline-block;
            font-size: 30px;
            font-weight: bold;
            background: #ffd500;
            color: #111;
            padding: 10px 18px;
            border-radius: 10px;
        }

        h2 {
            text-align: center;
            margin: 0 0 8px;
            font-size: 22px;
        }

        .subtitle {
            text-align: center;
            color: #777;
            margin-bottom: 22px;
            font-size: 14px;
        }

        .amount-box {
            background: #f7f7f7;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: center;
        }

        .amount-label {
            font-size: 13px;
            color: #777;
            margin-bottom: 6px;
        }

        .amount {
            font-size: 25px;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            height: 50px;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 0 14px;
            font-size: 16px;
            outline: none;
            background: #fff;
            direction: ltr;
            text-align: left;
        }

        input:focus {
            border-color: #999;
        }

        button {
            width: 100%;
            border: 0;
            border-radius: 10px;
            height: 50px;
            margin-top: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: opacity .2s ease;
        }

        button:hover {
            opacity: .9;
        }

        .btn-primary {
            background: #ffd500;
            color: #111;
        }

        .btn-secondary {
            background: #ececec;
            color: #333;
            margin-top: 10px;
        }

        .hidden {
            display: none !important;
        }

        .message {
            margin-top: 16px;
            padding: 13px 14px;
            border-radius: 10px;
            display: none;
            font-size: 14px;
            line-height: 1.6;
        }

        .message.error {
            background: #ffe7e7;
            color: #b00020;
            border: 1px solid #ffc4c4;
        }

        .message.success {
            background: #e9f8ee;
            color: #167a35;
            border: 1px solid #bde8ca;
        }

        .message.info {
            background: #edf4ff;
            color: #245a9c;
            border: 1px solid #cadcff;
        }

        .loading {
            opacity: .6;
            pointer-events: none;
        }

        .help {
            font-size: 13px;
            color: #777;
            margin-top: 7px;
            line-height: 1.5;
        }

        .security-note {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #eee;
            color: #888;
            text-align: center;
            font-size: 12px;
            line-height: 1.6;
        }

        .otp-title {
            text-align: center;
            font-weight: bold;
            margin-bottom: 16px;
            font-size: 17px;
        }

        .phone-display {
            background: #fafafa;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            text-align: center;
            direction: ltr;
            font-weight: bold;
        }
    </style>
</head>

<body>

<div class="wrapper">

    <div class="card">

        <div class="logo">
            <div class="logo-title">
                MTN Cash
            </div>
        </div>

        <h2>الدفع عبر MTN Cash</h2>

        <div class="subtitle">
            أدخل رقم MTN المرتبط بمحفظتك لإكمال عملية الدفع
        </div>

        <div class="amount-box">

            <div class="amount-label">
                المبلغ المطلوب
            </div>

            <div class="amount">
                {{ number_format((float) $payment->payment_amount, 2) }}
                {{ $payment->currency_code }}
            </div>

        </div>

        @if(!empty($fatalError))

            <div class="message error"
                 style="display:block; margin-bottom:15px;">
                {{ $fatalError }}
            </div>

        @endif


        <div
            id="phone-section"
            class="{{ !empty($fatalError) ? 'hidden' : '' }}"
        >

            <div class="form-group">

                <label class="label" for="phone">
                    رقم الموبايل
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    placeholder="09XXXXXXXX"
                    autocomplete="tel"
                    maxlength="15"
                >

                <div class="help">
                    يمكنك إدخال الرقم بالشكل 09XXXXXXXX أو 9639XXXXXXXX.
                </div>

            </div>

            <button
                type="button"
                class="btn-primary"
                id="send-code-btn"
            >
                إرسال رمز التحقق
            </button>

        </div>


        <div
            id="otp-section"
            class="hidden"
        >

            <div class="otp-title">
                أدخل رمز التحقق
            </div>

            <div
                id="phone-display"
                class="phone-display"
            ></div>

            <div class="form-group">

                <label class="label" for="otp">
                    رمز التحقق المرسل عبر SMS
                </label>

                <input
                    type="text"
                    id="otp"
                    name="otp"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                >

            </div>

            <button
                type="button"
                class="btn-primary"
                id="confirm-btn"
            >
                تأكيد الدفع
            </button>

            <button
                type="button"
                class="btn-secondary"
                id="change-phone-btn"
            >
                تغيير رقم الهاتف
            </button>

        </div>


        <div
            id="message"
            class="message"
        ></div>


        <div class="security-note">
            لا تغلق الصفحة أثناء تنفيذ عملية الدفع.
            لن يتم اعتبار العملية ناجحة إلا بعد التحقق النهائي من MTN Cash.
        </div>

    </div>

</div>


<script>

    const paymentId = @json($payment->id);

    const initiateUrl = @json(route('mtn-cash.initiate'));
    const confirmUrl = @json(route('mtn-cash.confirm'));
    const csrfToken = @json(csrf_token());

    const phoneInput = document.getElementById('phone');
    const otpInput = document.getElementById('otp');

    const sendCodeButton =
        document.getElementById('send-code-btn');

    const confirmButton =
        document.getElementById('confirm-btn');

    const changePhoneButton =
        document.getElementById('change-phone-btn');

    const phoneSection =
        document.getElementById('phone-section');

    const otpSection =
        document.getElementById('otp-section');

    const phoneDisplay =
        document.getElementById('phone-display');

    const messageBox =
        document.getElementById('message');


    function showMessage(message, type = 'error') {

        messageBox.className =
            'message ' + type;

        messageBox.innerText = message;

        messageBox.style.display = 'block';
    }


    function clearMessage() {

        messageBox.style.display = 'none';

        messageBox.innerText = '';
    }


    function setLoading(button, loading) {

        if (loading) {

            button.classList.add('loading');
            button.disabled = true;

        } else {

            button.classList.remove('loading');
            button.disabled = false;
        }
    }


    function normalizeVisiblePhone(phone) {

        return phone
            .replace(/\s+/g, '')
            .replace(/[^0-9+]/g, '');
    }


    async function parseJsonResponse(response) {

        try {

            return await response.json();

        } catch (error) {

            return {
                status: false,
                message: 'استجابة غير صالحة من الخادم.'
            };
        }
    }


    sendCodeButton?.addEventListener(
        'click',
        async function () {

            clearMessage();

            const phone =
                normalizeVisiblePhone(
                    phoneInput.value.trim()
                );

            if (!phone) {

                showMessage(
                    'يرجى إدخال رقم الهاتف.'
                );

                phoneInput.focus();

                return;
            }


            if (phone.length < 9) {

                showMessage(
                    'رقم الهاتف غير صحيح.'
                );

                phoneInput.focus();

                return;
            }


            setLoading(
                sendCodeButton,
                true
            );


            try {

                const response =
                    await fetch(
                        initiateUrl,
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                'Accept':
                                    'application/json',

                                'X-CSRF-TOKEN':
                                    csrfToken
                            },

                            body: JSON.stringify({
                                payment_id:
                                    paymentId,

                                phone:
                                    phone
                            })
                        }
                    );


                const data =
                    await parseJsonResponse(
                        response
                    );


                /*
                 * ممكن initiate يكتشف أن الفاتورة
                 * مدفوعة مسبقاً ويرجع redirect_url.
                 */
                if (
                    data.status === true &&
                    data.redirect_url
                ) {

                    showMessage(
                        data.message ??
                        'تم الدفع بنجاح.',
                        'success'
                    );

                    setTimeout(
                        function () {

                            window.location.href =
                                data.redirect_url;

                        },
                        800
                    );

                    return;
                }


                if (
                    !response.ok ||
                    data.status !== true
                ) {

                    showMessage(
                        data.message ??
                        'تعذر إرسال رمز التحقق.'
                    );

                    return;
                }


                phoneDisplay.innerText =
                    phone;


                phoneSection.classList.add(
                    'hidden'
                );


                otpSection.classList.remove(
                    'hidden'
                );


                showMessage(
                    data.message ??
                    'تم إرسال رمز التحقق إلى هاتفك.',
                    'success'
                );


                setTimeout(
                    function () {

                        otpInput.focus();

                    },
                    200
                );


            } catch (error) {

                showMessage(
                    'تعذر الاتصال بخدمة الدفع. حاول مرة أخرى.'
                );

            } finally {

                setLoading(
                    sendCodeButton,
                    false
                );
            }

        }
    );


    confirmButton?.addEventListener(
        'click',
        async function () {

            clearMessage();

            const otp =
                otpInput.value
                    .trim()
                    .replace(/\D/g, '');


            if (!otp) {

                showMessage(
                    'يرجى إدخال رمز التحقق.'
                );

                otpInput.focus();

                return;
            }


            if (otp.length !== 6) {

                showMessage(
                    'رمز التحقق يجب أن يتكون من 6 أرقام.'
                );

                otpInput.focus();

                return;
            }


            setLoading(
                confirmButton,
                true
            );


            try {

                const response =
                    await fetch(
                        confirmUrl,
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                'Accept':
                                    'application/json',

                                'X-CSRF-TOKEN':
                                    csrfToken
                            },

                            body: JSON.stringify({
                                payment_id:
                                    paymentId,

                                otp:
                                    otp
                            })
                        }
                    );


                const data =
                    await parseJsonResponse(
                        response
                    );


                if (
                    response.status === 202
                ) {

                    showMessage(
                        data.message ??
                        'عملية الدفع ما زالت قيد المعالجة.',
                        'info'
                    );

                    return;
                }


                if (
                    !response.ok ||
                    data.status !== true
                ) {

                    showMessage(
                        data.message ??
                        'تعذر إتمام عملية الدفع.'
                    );

                    otpInput.select();

                    return;
                }


                showMessage(
                    data.message ??
                    'تم الدفع بنجاح. جارٍ تحويلك...',
                    'success'
                );


                confirmButton.disabled =
                    true;


                changePhoneButton.disabled =
                    true;


                if (data.redirect_url) {

                    setTimeout(
                        function () {

                            window.location.href =
                                data.redirect_url;

                        },
                        900
                    );

                } else {

                    showMessage(
                        'تم الدفع بنجاح، ولكن لم يتم العثور على رابط التحويل.',
                        'success'
                    );
                }


            } catch (error) {

                showMessage(
                    'حدث خطأ أثناء تأكيد عملية الدفع. لم يتم اعتبار العملية مدفوعة.'
                );

            } finally {

                setLoading(
                    confirmButton,
                    false
                );
            }

        }
    );


    changePhoneButton?.addEventListener(
        'click',
        function () {

            clearMessage();

            otpInput.value = '';

            otpSection.classList.add(
                'hidden'
            );

            phoneSection.classList.remove(
                'hidden'
            );

            phoneInput.focus();
        }
    );


    /*
     * Enter على رقم الهاتف = إرسال OTP
     */
    phoneInput?.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Enter') {

                event.preventDefault();

                sendCodeButton.click();
            }
        }
    );


    /*
     * Enter على OTP = تأكيد الدفع
     */
    otpInput?.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Enter') {

                event.preventDefault();

                confirmButton.click();
            }
        }
    );


    /*
     * يمنع إدخال أحرف بخانة OTP.
     */
    otpInput?.addEventListener(
        'input',
        function () {

            this.value =
                this.value
                    .replace(/\D/g, '')
                    .slice(0, 6);
        }
    );

</script>

</body>
</html>