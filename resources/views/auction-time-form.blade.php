<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjust Auction Time — #{{ $auction->id }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 24px;
        }

        .card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 40px 48px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
            color: #fff;
        }

        .badge {
            display: inline-block;
            background: rgba(99, 102, 241, 0.25);
            border: 1px solid rgba(99, 102, 241, 0.45);
            color: #a5b4fc;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 16px;
        }

        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .subtitle {
            font-size: 13px;
            color: rgba(255,255,255,0.45);
            margin-bottom: 32px;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #86efac;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 24px;
        }

        .alert-error ul { padding-left: 16px; margin-top: 4px; }
        .alert-error li { margin-top: 2px; }

        .field { margin-bottom: 20px; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.55);
            margin-bottom: 8px;
        }

        input[type="datetime-local"] {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
            color-scheme: dark;
        }

        input[type="datetime-local"]:focus {
            border-color: rgba(99, 102, 241, 0.7);
            background: rgba(99, 102, 241, 0.08);
        }

        .field-error {
            font-size: 11px;
            color: #fca5a5;
            margin-top: 5px;
        }

        .divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin: 28px 0;
        }

        .current-times {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 28px;
        }

        .time-chip {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 10px;
            padding: 10px 14px;
        }

        .time-chip .tc-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(255,255,255,0.35);
            margin-bottom: 4px;
        }

        .time-chip .tc-value {
            font-size: 12px;
            font-weight: 600;
            color: rgba(255,255,255,0.75);
        }

        button[type="submit"] {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: 0.3px;
            transition: opacity 0.2s, transform 0.15s;
            margin-top: 8px;
        }

        button[type="submit"]:hover  { opacity: 0.88; transform: translateY(-1px); }
        button[type="submit"]:active { opacity: 1;    transform: translateY(0);    }
    </style>
</head>
<body>

<div class="card">

    <div class="badge">Auction #{{ $auction->id }}</div>

    <h1>{{ translate('Adjust_Auction_Time') }}</h1>
    <p class="subtitle">
        {{ $auction->name ?? ('Product #' . $auction->id) }}
    </p>

    {{-- Success message --}}
    @if(session('success'))
        <div class="alert-success">
            ✓ {{ session('success') }}
        </div>
    @endif

    {{-- Validation errors --}}
    @if($errors->any())
        <div class="alert-error">
            <strong>Please fix the following:</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Current times display --}}
    <div class="current-times">
        <div class="time-chip">
            <div class="tc-label">Current Start</div>
            <div class="tc-value">
                {{ $auction->start_time ? $auction->start_time->format('M d, Y H:i') : '—' }}
            </div>
        </div>
        <div class="time-chip">
            <div class="tc-label">Current End</div>
            <div class="tc-value">
                {{ $auction->end_time ? $auction->end_time->format('M d, Y H:i') : '—' }}
            </div>
        </div>
    </div>

    <hr class="divider">

    {{-- Form --}}
    <form method="POST" action="{{ route('admin.auction.time.update', $auction->id) }}">
        @csrf

        <div class="field">
            <label for="start_time">{{ translate('New_Start_Time') }}</label>
            <input
                type="datetime-local"
                id="start_time"
                name="start_time"
                value="{{ old('start_time', $auction->start_time?->format('Y-m-d\TH:i')) }}"
            >
            @error('start_time')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="end_time">{{ translate('New_End_Time') }}</label>
            <input
                type="datetime-local"
                id="end_time"
                name="end_time"
                value="{{ old('end_time', $auction->end_time?->format('Y-m-d\TH:i')) }}"
            >
            @error('end_time')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit">{{ translate('Update_Auction_Times') }}</button>
    </form>

</div>

</body>
</html>
