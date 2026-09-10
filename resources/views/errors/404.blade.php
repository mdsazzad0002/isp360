<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        .card { width: 100%; max-width: 26rem; text-align: center; }
        .icon-wrap {
            margin: 0 auto;
            width: 6rem;
            height: 6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: #eff6ff;
            color: #0ea5e9;
        }
        .icon-wrap svg { width: 2.75rem; height: 2.75rem; }
        h1 { margin: 1.5rem 0 0; font-size: 3.75rem; font-weight: 700; color: #1e293b; line-height: 1; }
        p.title { margin: 0.5rem 0 0; font-size: 1.125rem; font-weight: 600; color: #334155; }
        p.desc { margin: 0.25rem 0 0; font-size: 0.875rem; color: #64748b; }
        .actions { margin-top: 1.5rem; display: flex; align-items: center; justify-content: center; gap: 0.75rem; flex-wrap: wrap; }
        a.btn, button.btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
        }
        a.btn.primary { background: #0284c7; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        a.btn.primary:hover { background: #0369a1; }
        button.btn.secondary { background: #fff; color: #475569; border-color: #cbd5e1; }
        button.btn.secondary:hover { background: #f8fafc; }
    </style>
</head>

<body>
    <div class="card">
        <div class="icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m0 0 3.26-3.26" />
            </svg>
        </div>
        <h1>404</h1>
        <p class="title">Page Not Found</p>
        <p class="desc">The page you're looking for doesn't exist or may have been moved.</p>
        <div class="actions">
            <a href="/" class="btn primary">Go to Dashboard</a>
            <button type="button" class="btn secondary" onclick="history.back()">Go Back</button>
        </div>
    </div>
</body>

</html>
