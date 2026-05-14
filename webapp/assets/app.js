/* Rose Store WebApp — Vanilla JS */

const tg = window.Telegram.WebApp;
tg.ready();
tg.expand();
tg.setHeaderColor('#8B1A3A');
tg.setBackgroundColor('#FCE4EC');

// ========================
// State
// ========================
const state = {
    initData: tg.initData,
    user: null,
    services: null,
    stats: null,
    receipts: null,
    activeTab: 'services',
};

// ========================
// API helper
// ========================
async function apiCall(action, body = {}) {
    const params = new URLSearchParams({ action });
    const isGet  = Object.keys(body).length === 0;
    const url    = 'api.php?' + params.toString();
    const opts   = {
        method: isGet ? 'GET' : 'POST',
        headers: { 'X-Telegram-Init-Data': state.initData },
    };
    if (!isGet) {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.body = new URLSearchParams({ action, ...body }).toString();
    }
    const res  = await fetch(url, opts);
    return res.json();
}

// ========================
// Toast
// ========================
function showToast(msg) {
    let t = document.getElementById('toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'toast';
        t.className = 'toast';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}

// ========================
// Tabs
// ========================
function switchTab(tab) {
    state.activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.tab-pane').forEach(p => p.style.display = p.dataset.pane === tab ? '' : 'none');
}

// ========================
// Copy to clipboard
// ========================
function copyText(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text)
            .then(() => tg.showAlert('✓ کپی شد'))
            .catch(() => showToast('خطا در کپی'));
    } else {
        showToast('✓ کپی شد');
    }
}

// ========================
// Formatters
// ========================
function fmtNumber(n) {
    return Number(n).toLocaleString('fa-IR');
}

// ========================
// Render: Services Tab
// ========================
function renderServices(services) {
    const el = document.getElementById('pane-services');
    if (!services || services.length === 0) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📦</div><p>هنوز سرویسی خریداری نکرده‌اید.</p></div>`;
        return;
    }
    el.innerHTML = services.map(s => {
        const pct      = s.used_pct ?? 0;
        const warnCls  = pct >= 90 ? 'warn' : '';
        const statusBadge = s.status === 'active'
            ? '<span class="badge badge-active">فعال</span>'
            : s.status === 'disabled'
                ? '<span class="badge badge-inactive">غیرفعال</span>'
                : '<span class="badge badge-warn">نامشخص</span>';

        const trafficHtml = s.total_bytes
            ? `<div class="traffic-bar-wrap">
                <div class="traffic-bar-label"><span>مصرف</span><span>${s.used_fmt} از ${s.total_fmt}</span></div>
                <progress class="traffic ${warnCls}" value="${pct}" max="100"></progress>
               </div>`
            : '';

        return `<div class="service-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <div class="service-name">${s.plan_name}</div>${statusBadge}
            </div>
            ${trafficHtml}
            <div class="service-meta">
                <div>⚡ باقیمانده: <span>${s.remain_fmt ?? '–'}</span></div>
                <div>⏳ انقضا: <span>${s.expiry_jalali ?? '–'}</span></div>
                <div>📅 زمان باقیمانده: <span>${s.days_until ?? '–'}</span></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button class="btn btn-outline btn-sm" onclick="copyText(${JSON.stringify(s.email)})">🔑 شناسه</button>
                ${s.link ? `<button class="btn btn-primary btn-sm" onclick="copyText(${JSON.stringify(s.link)})">🔗 کپی لینک</button>` : ''}
            </div>
        </div>`;
    }).join('');
}

// ========================
// Render: Wallet Tab
// ========================
function renderWallet(ctx) {
    const el = document.getElementById('pane-wallet');
    el.innerHTML = `
        <div class="wallet-banner">
            <div class="wallet-amount">${fmtNumber(ctx.wallet)} <small style="font-size:14px">تومان</small></div>
            <div class="wallet-label">موجودی کیف پول</div>
        </div>
        <div class="card">
            <div class="card-title">👤 حساب کاربری</div>
            <div class="service-meta">
                <div>🆔 آیدی: <span>${ctx.user_id}</span></div>
                ${ctx.username ? `<div>📎 یوزرنیم: <span>@${ctx.username}</span></div>` : ''}
                <div>💎 سطح: <span>${ctx.is_reseller ? 'نماینده' : 'کاربر عادی'}</span></div>
            </div>
        </div>
        <p class="text-muted" style="text-align:center;margin-top:8px">برای شارژ کیف پول از ربات اقدام کنید.</p>`;
}

// ========================
// Render: Admin Stats Tab
// ========================
function renderStats(stats) {
    const el = document.getElementById('pane-stats');
    const campaignHtml = stats.campaign_active
        ? `<div class="campaign-banner">🔥 کمپین فعال: ${stats.campaign_pct}٪ تخفیف ${stats.campaign_label ? '— ' + stats.campaign_label : ''}</div>`
        : '';
    el.innerHTML = `
        ${campaignHtml}
        <div class="stats-grid">
            <div class="stat-item"><div class="stat-value">${fmtNumber(stats.total_users)}</div><div class="stat-label">کاربران</div></div>
            <div class="stat-item"><div class="stat-value">${fmtNumber(stats.total_orders)}</div><div class="stat-label">سرویس‌ها</div></div>
            <div class="stat-item"><div class="stat-value">${fmtNumber(stats.today_revenue)}</div><div class="stat-label">درآمد امروز (ت)</div></div>
            <div class="stat-item"><div class="stat-value">${fmtNumber(stats.total_revenue)}</div><div class="stat-label">کل درآمد (ت)</div></div>
        </div>
        <div class="card">
            <div class="card-title">📋 وضعیت</div>
            <div class="service-meta">
                <div>🤖 ربات: <span>${stats.bot_status == '1' ? '🟢 روشن' : '🔴 خاموش'}</span></div>
                <div>💎 نمایندگان: <span>${fmtNumber(stats.resellers)} نفر</span></div>
                <div>⏳ رسیدهای در انتظار: <span>${fmtNumber(stats.pending_count)} مورد</span></div>
            </div>
        </div>`;
}

// ========================
// Render: Admin Receipts Tab
// ========================
function renderReceipts(receipts) {
    const el = document.getElementById('pane-receipts');
    if (!receipts || receipts.length === 0) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">✅</div><p>رسیدی در انتظار تایید نیست.</p></div>`;
        return;
    }
    el.innerHTML = receipts.map(r => `
        <div class="receipt-item" id="receipt-${r.id}">
            <div class="receipt-meta">
                <div>👤 کاربر: <strong>${r.chat_id}</strong></div>
                <div>💵 مبلغ: <strong>${fmtNumber(r.amount)} تومان</strong></div>
                <div>📦 پلن: ${r.plan_id}</div>
                <div>🕐 زمان: ${r.created_at}</div>
            </div>
            <div class="receipt-actions">
                <button class="btn btn-primary btn-sm" onclick="approveReceipt(${r.id})">✅ تایید</button>
                <button class="btn btn-outline btn-sm" onclick="rejectReceipt(${r.id})">❌ رد</button>
            </div>
        </div>`).join('');
}

// ========================
// Admin Actions
// ========================
async function approveReceipt(txId) {
    tg.showConfirm('آیا این رسید تایید شود؟', async (ok) => {
        if (!ok) return;
        const res = await apiCall('approve_receipt', { tx_id: txId });
        if (res.ok) {
            document.getElementById('receipt-' + txId)?.remove();
            showToast('✅ تایید شد');
        } else {
            tg.showAlert('خطا: ' + (res.error || 'نامشخص'));
        }
    });
}

async function rejectReceipt(txId) {
    tg.showConfirm('آیا این رسید رد شود؟', async (ok) => {
        if (!ok) return;
        const res = await apiCall('reject_receipt', { tx_id: txId });
        if (res.ok) {
            document.getElementById('receipt-' + txId)?.remove();
            showToast('❌ رد شد');
        } else {
            tg.showAlert('خطا: ' + (res.error || 'نامشخص'));
        }
    });
}

// ========================
// Boot
// ========================
async function boot() {
    const ctxRes = await apiCall('get_user_context');
    if (!ctxRes.ok) {
        document.getElementById('app-content').innerHTML =
            '<div class="empty-state"><div class="empty-icon">🔒</div><p>خطای احراز هویت</p></div>';
        return;
    }

    state.user  = ctxRes.data;
    const isAdmin = state.user.is_admin;

    // نمایش پنل مناسب
    document.getElementById('user-name').textContent = state.user.name || 'کاربر گرامی';
    document.getElementById('app-content').style.display = '';
    document.getElementById('loading-screen').style.display = 'none';

    if (isAdmin) {
        document.getElementById('tab-bar').innerHTML = `
            <button class="tab-btn active" data-tab="stats"    onclick="switchTab('stats')">
                <span class="tab-icon">📊</span>آمار
            </button>
            <button class="tab-btn" data-tab="receipts" onclick="switchTab('receipts')">
                <span class="tab-icon">🧾</span>رسیدها
            </button>`;

        document.getElementById('pane-services').style.display = 'none';
        document.getElementById('pane-wallet').style.display   = 'none';

        const statsRes    = await apiCall('get_stats');
        const receiptsRes = await apiCall('get_pending_receipts');
        renderStats(statsRes.data || {});
        renderReceipts(receiptsRes.data || []);
        switchTab('stats');
    } else {
        document.getElementById('tab-bar').innerHTML = `
            <button class="tab-btn active" data-tab="services" onclick="switchTab('services')">
                <span class="tab-icon">📦</span>سرویس‌ها
            </button>
            <button class="tab-btn" data-tab="wallet" onclick="switchTab('wallet')">
                <span class="tab-icon">💰</span>کیف پول
            </button>`;

        document.getElementById('pane-stats').style.display    = 'none';
        document.getElementById('pane-receipts').style.display = 'none';

        renderWallet(state.user);

        // بارگذاری سرویس‌ها
        document.getElementById('pane-services').innerHTML = '<div class="loading"><div class="spinner"></div><p>در حال بارگذاری سرویس‌ها...</p></div>';
        const svcsRes = await apiCall('get_services');
        renderServices(svcsRes.data || []);
        switchTab('services');
    }
}

boot();
