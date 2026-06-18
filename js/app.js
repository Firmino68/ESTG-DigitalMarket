// ╔══════════════════════════════════════════════════════════╗
// ║  DIGITALMARKET — Frontend ligado a PHP + MySQL            ║
// ║  Toda a persistência agora passa pela API REST em /api/   ║
// ╚══════════════════════════════════════════════════════════╝

'use strict';

// ──────────────────────────────────────────────────────────
// CONFIGURAÇÃO DA API
// Caminho relativo (sem "/" no início) — funciona automaticamente
// seja a pasta colocada na raiz do htdocs ou num subdiretório
// (ex.: htdocs/digitalmarket/). Não precisa de ser ajustado.
// ──────────────────────────────────────────────────────────
const API_BASE = 'api';

let products    = [];
let cart        = [];
let currentUser = null;
let authToken   = localStorage.getItem('dm_token') || null;
let prevView    = 'home';
let _filterType = 'all';
let _filterCat  = '';
let _editingProductId = null;
let _gigSelectedFile  = null;

const EMOJI = { 'Web Dev':'💻','Ebooks':'📚','Design':'🎨','Vídeo':'🎬','Motivação':'🚀','Templates':'📐' };
const BG = {
  'Web Dev':'linear-gradient(135deg,#0a0a1e,#1a1a3e)','Ebooks':'linear-gradient(135deg,#0a140a,#1a2e14)',
  'Design':'linear-gradient(135deg,#1e0a0a,#3e1a0a)','Vídeo':'linear-gradient(135deg,#0a0e1e,#0a1e2e)',
  'Motivação':'linear-gradient(135deg,#1e1a0a,#2e280a)','Templates':'linear-gradient(135deg,#0e0a1e,#1e0a2e)'
};

// ──────────────────────────────────────────────────────────
// HELPER — chamadas fetch() com JWT automático
// ──────────────────────────────────────────────────────────
async function api(path, { method = 'GET', body = null, isFormData = false } = {}) {
  const headers = {};
  if (authToken) headers['Authorization'] = 'Bearer ' + authToken;
  if (!isFormData && body) headers['Content-Type'] = 'application/json';

  const opts = { method, headers };
  if (body) opts.body = isFormData ? body : JSON.stringify(body);

  const res = await fetch(API_BASE + path, opts);
  let json;
  try { json = await res.json(); }
  catch (e) { throw new Error('Resposta inválida do servidor.'); }

  if (!res.ok || !json.success) {
    throw new Error(json.error || 'Erro desconhecido (' + res.status + ')');
  }
  return json.data;
}

// ──────────────────────────────────────────────────────────
// ARRANQUE — restaura sessão e carrega produtos
// ──────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const theme = localStorage.getItem('dm_theme');
  if (theme === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
    document.querySelector('.theme-btn').textContent = '🌙';
  }

  // Sessão persistente via JWT guardado no localStorage (#17)
  if (authToken) {
    try {
      currentUser = await api('/auth/me');
    } catch (e) {
      authToken = null;
      localStorage.removeItem('dm_token');
    }
  }
  updateAuthNav();

  await loadProducts();
  renderFeatured();
  if (cart.length) updateCartBadge();
});

// ──────────────────────────────────────────────────────────
// PRODUTOS — carregados da API (tabela products no MySQL)
// ──────────────────────────────────────────────────────────
async function loadProducts(params = {}) {
  const qs = new URLSearchParams(params).toString();
  try {
    const data = await api('/products' + (qs ? '?' + qs : ''));
    products = data.items.map(normalizeProduct);
    return data;
  } catch (e) {
    showToast('Erro ao carregar produtos: ' + e.message, 'error');
    return { items: [], total: 0 };
  }
}

function normalizeProduct(p) {
  return {
    id: String(p.id),
    title: p.title,
    desc: p.description,
    price: parseFloat(p.price),
    type: p.type,
    cat: p.category,
    rating: parseFloat(p.rating || 5),
    file: p.file_name,
    seller: p.seller_name,
    sellerEmail: p.seller_email,
    sellerColor: p.seller_color || '#7c6cfc',
    salesCount: p.sales_count || p.actual_sales || 0,
    sellerId: p.seller_id,
  };
}

function cardHtml(p) {
  const bg = BG[p.cat] || 'var(--bg4)';
  const em = EMOJI[p.cat] || '📦';
  return `
  <div class="product-card" onclick="openProduct('${p.id}')">
    <div class="card-thumb" style="background:${bg}">${em}<span class="card-badge">${p.type==='digital'?'DIGITAL':'SERVIÇO'}</span></div>
    <div class="card-body">
      <div class="card-seller">
        <div class="seller-avatar" style="background:${p.sellerColor}">${(p.seller||'?')[0]}</div>
        <span style="font-size:12px;color:var(--text2)">${p.seller||'—'}</span>
      </div>
      <div class="card-title">${p.title}</div>
      <div class="card-desc">${(p.desc||'').substring(0,70)}...</div>
      <div class="card-footer"><div class="card-price">${p.price.toFixed(2)}€</div><div class="card-rating">⭐ ${p.rating}</div></div>
      <button class="add-btn fw" style="margin-top:8px" onclick="event.stopPropagation();addToCart('${p.id}')">🛒 Adicionar</button>
    </div>
  </div>`;
}

function renderFeatured() {
  const g = document.getElementById('featured-grid');
  if (g) g.innerHTML = [...products].sort((a,b)=>b.salesCount-a.salesCount).slice(0,4).map(cardHtml).join('');
  document.getElementById('stat-products').textContent = products.length;
  document.getElementById('stat-sellers').textContent  = [...new Set(products.map(p=>p.sellerEmail))].length;
}

async function filterProducts() {
  const q    = document.getElementById('search-input')?.value || '';
  const sort = document.getElementById('sort-select')?.value || 'sales';
  const sortMap = { default:'sales', 'price-asc':'price-asc', 'price-desc':'price-desc', rating:'rating' };
  await loadProducts({ q, cat: _filterCat, type: _filterType === 'all' ? '' : _filterType, sort: sortMap[sort] || 'sales' });
  const g = document.getElementById('catalog-grid');
  if (!g) return;
  g.innerHTML = products.length
    ? products.map(cardHtml).join('')
    : `<div class="empty-state fw"><div class="big-icon">🔍</div><h3>Sem resultados</h3><p>Tenta outros filtros.</p></div>`;
}

function setType(t, el) {
  _filterType = t;
  document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
  el.classList.add('active');
  filterProducts();
}
function setCatChip(c, el) {
  _filterCat = c;
  document.querySelectorAll('.filter-chip').forEach(x=>x.classList.remove('active'));
  el.classList.add('active');
  filterProducts();
}
function setCat(c) { _filterCat = c; nav('catalog'); }

// Aliases para compatibilidade com nomes usados no HTML original
function setCategory(c) { setCat(c); }
function setTypeFilter(t, el) { setType(t, el); }
function setCatFilter(c, el) { setCatChip(c, el); }

async function openProduct(id) {
  let p = products.find(x=>x.id===String(id));
  if (!p) {
    try { p = normalizeProduct(await api('/products?id=' + id)); }
    catch(e) { showToast(e.message, 'error'); return; }
  }
  const bg = BG[p.cat] || 'var(--bg4)';
  const em = EMOJI[p.cat] || '📦';
  document.getElementById('product-detail-content').innerHTML = `
    <div><div class="detail-thumb" style="background:${bg}">${em}</div></div>
    <div class="detail-actions">
      <div class="detail-meta"><span class="tag">${p.cat}</span><span class="tag">${p.type}</span><span class="tag">⭐ ${p.rating}</span><span class="tag">${p.salesCount} vendas</span></div>
      <h1 class="detail-title">${p.title}</h1>
      <div class="detail-price">${p.price.toFixed(2)}€</div>
      <p class="detail-desc">${p.desc}</p>
      <div class="seller-card"><h4>Vendedor</h4>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="seller-avatar" style="background:${p.sellerColor};width:32px;height:32px;font-size:14px">${(p.seller||'?')[0]}</div>
          <div><div style="font-weight:600;font-size:14px">${p.seller}</div><div style="font-size:12px;color:var(--text3)">${p.sellerEmail}</div></div>
        </div>
      </div>
      <button class="btn-buy" style="margin-top:1rem" onclick="buyNow('${p.id}')">Comprar Agora</button>
      <button class="btn-cart" onclick="addToCart('${p.id}')">+ Adicionar ao Carrinho</button>
    </div>`;
  nav('product');
}

// ──────────────────────────────────────────────────────────
// CARRINHO — persistido na tabela cart_items (server-side)
// Se não autenticado: usa array local apenas para a sessão.
// ──────────────────────────────────────────────────────────
function addToCart(id) {
  const p = products.find(x=>x.id===String(id));
  if (!p) return;
  if (cart.find(c=>c.id===p.id)) { showToast('Já está no carrinho!','warning'); return; }
  cart.push(p);
  updateCartBadge();
  showToast(`✅ "${p.title.slice(0,30)}…" adicionado!`,'success');

  // Persistir no servidor se autenticado (#11 carrinho persistente)
  if (currentUser) {
    api('/cart', { method:'POST', body:{ product_id: id } }).catch(()=>{});
  }
}

function buyNow(id) {
  if (!currentUser) { showToast('Faz login para comprar.','error'); nav('login'); return; }
  const p = products.find(x=>x.id===String(id));
  if (!p) return;
  if (!cart.find(c=>c.id===p.id)) cart.push(p);
  updateCartBadge();
  nav('checkout');
}

function removeFromCart(index) {
  const item = cart[index];
  cart.splice(index, 1);
  updateCartBadge();
  renderCart();
  if (currentUser && item) {
    api('/cart?id=' + item.id, { method:'DELETE' }).catch(()=>{});
  }
}

function updateCartBadge() {
  const el = document.getElementById('cart-count');
  el.textContent = cart.length;
  el.style.display = cart.length ? 'flex' : 'none';
}

function renderCart() {
  const c = document.getElementById('cart-content');
  if (!cart.length) {
    c.innerHTML = `<div class="empty-state"><div class="big-icon">🛒</div><h3>Carrinho vazio</h3><p>Explora o catálogo.</p><button class="btn btn-primary" style="margin-top:1rem" onclick="nav('catalog')">Ir ao Catálogo</button></div>`;
    return;
  }
  let total = 0;
  const items = cart.map((p,i)=>{
    total += p.price;
    return `<div class="cart-item"><div class="cart-item-icon">${EMOJI[p.cat]||'📦'}</div><div class="cart-item-info"><div class="cart-item-title">${p.title}</div><div class="cart-item-seller">Por ${p.seller}</div></div><div class="cart-item-price">${p.price.toFixed(2)}€</div><button class="remove-btn" onclick="removeFromCart(${i})">✕</button></div>`;
  }).join('');
  c.innerHTML = `<div class="cart-items">${items}</div>
    <div class="cart-summary">
      <div class="summary-row"><span>Subtotal</span><span>${total.toFixed(2)}€</span></div>
      <div class="summary-row"><span>Taxas</span><span>0.00€</span></div>
      <div class="summary-row summary-total"><span>Total</span><span>${total.toFixed(2)}€</span></div>
      <button class="btn-buy fw" style="margin-top:1rem" onclick="nav('checkout')">Avançar para Checkout →</button>
    </div>`;
}

// ──────────────────────────────────────────────────────────
// CHECKOUT — validação Luhn no cliente (UX) + servidor valida tudo
// ──────────────────────────────────────────────────────────
function luhn(n){let s=0,a=false;for(let i=n.length-1;i>=0;i--){let d=+n[i];if(a&&(d*=2)>9)d-=9;s+=d;a=!a;}return s%10===0;}
function fmtCardNum(el){let v=el.value.replace(/\D/g,'').slice(0,16);el.value=v.match(/.{1,4}/g)?.join(' ')||v;}
function fmtCardExp(el){let v=el.value.replace(/\D/g,'');if(v.length>2)v=v.slice(0,2)+'/'+v.slice(2,4);el.value=v;}

async function processCheckout() {
  if (!currentUser) { showToast('Faz login para comprar.','error'); nav('login'); return; }
  if (!cart.length) { showToast('Carrinho vazio.','warning'); return; }

  const name  = document.getElementById('co-name').value.trim();
  const email = document.getElementById('co-email').value.trim();
  const msg   = document.getElementById('co-msg')?.value.trim() || '';
  const ccNum = document.getElementById('cc-num')?.value.replace(/\s/g,'') || '';
  const ccExp = document.getElementById('cc-exp')?.value || '';
  const ccCvv = document.getElementById('cc-cvv')?.value || '';
  const ccNm  = document.getElementById('cc-name')?.value.trim() || '';

  // Validação no cliente (UX rápida — servidor reforça tudo)
  const errs = [];
  if (!name || name.split(' ').filter(Boolean).length < 2) errs.push('Nome completo obrigatório');
  if (!email || !email.endsWith('@piaget.pt')) errs.push('Email @piaget.pt obrigatório');
  if (!ccNum || !/^\d{16}$/.test(ccNum) || !luhn(ccNum)) errs.push('Número de cartão inválido');
  if (!ccExp || !/^\d{2}\/\d{2}$/.test(ccExp)) errs.push('Validade inválida');
  if (!ccCvv || !/^\d{3,4}$/.test(ccCvv)) errs.push('CVV inválido');
  if (!ccNm) errs.push('Nome no cartão obrigatório');

  const errBox = document.getElementById('checkout-errors');
  if (errs.length) {
    errBox.style.display='block';
    errBox.innerHTML='<b>⚠ Corrige:</b><br>' + errs.map(e=>'• '+e).join('<br>');
    errBox.scrollIntoView({behavior:'smooth',block:'nearest'});
    return;
  }
  errBox.style.display='none';

  try {
    const result = await api('/checkout', { method:'POST', body:{
      name, email, message: msg,
      card_number: ccNum, card_exp: ccExp, card_cvv: ccCvv, card_name: ccNm
    }});

    // Mostrar ficheiros para download (#16 — download real do servidor)
    const filesHtml = result.items.map(item => {
      const em = EMOJI[item.type==='digital' ? 'Web Dev' : 'Web Dev'] || '📦';
      if (item.type === 'digital') {
        return `<div class="file-item">
          <span style="font-size:1.5rem">📦</span>
          <div style="flex:1;min-width:0"><div style="font-weight:500;font-size:14px">${item.title}</div></div>
          <button class="btn btn-primary btn-sm" onclick="downloadProduct('${item.id}')">⬇ Download</button>
        </div>`;
      }
      return `<div class="file-item"><span style="font-size:1.5rem">🔧</span><div><div style="font-weight:500;font-size:14px">${item.title}</div><div style="font-size:12px;color:var(--text3)">Serviço — o vendedor entrará em contacto em 24h</div></div></div>`;
    }).join('');

    document.getElementById('download-files').innerHTML = filesHtml;
    cart = [];
    updateCartBadge();
    nav('success');
  } catch(e) {
    errBox.style.display='block';
    errBox.innerHTML = '<b>⚠ Erro no servidor:</b><br>' + e.message;
  }
}

// Download seguro via API (verifica compra no servidor) (#16)
async function downloadProduct(productId) {
  if (!authToken) { showToast('Sessão expirada.','error'); return; }
  try {
    const url = `${API_BASE}/products/download?id=${productId}`;
    const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + authToken } });
    if (!res.ok) {
      const j = await res.json().catch(()=>({error:'Erro no download'}));
      throw new Error(j.error || 'Erro no download');
    }
    const blob = await res.blob();
    const disposition = res.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="(.+)"/);
    const fileName = match ? match[1] : 'produto_' + productId;

    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(()=>URL.revokeObjectURL(a.href), 2000);
    showToast('✅ Download iniciado!','success');
  } catch(e) {
    showToast('Erro: ' + e.message, 'error');
  }
}

// ──────────────────────────────────────────────────────────
// AUTENTICAÇÃO REAL (#17, #18) — ligado à tabela `users`
// ──────────────────────────────────────────────────────────
function updatePwStrength(pass) {
  const bar = document.getElementById('pw-bar');
  const lbl = document.getElementById('pw-label');
  if (!bar) return;
  let score = 0;
  if (pass.length>=8) score++;
  if (pass.length>=12) score++;
  if (/[A-Z]/.test(pass)&&/[a-z]/.test(pass)) score++;
  if (/[0-9]/.test(pass)) score++;
  if (/[^A-Za-z0-9]/.test(pass)) score++;
  const colors=['#fb7185','#f97316','#f59e0b','#4ade80','#2dd4bf'];
  const texts=['Muito fraca','Fraca','Razoável','Boa','Forte'];
  bar.style.width=((score+1)/5*100)+'%';
  bar.style.background=colors[score]||colors[0];
  lbl.textContent=pass?texts[score]||texts[0]:'';
  lbl.style.color=colors[score]||colors[0];
}

async function doLogin() {
  const email = document.getElementById('login-email').value.trim();
  const pass  = document.getElementById('login-pass').value;
  const errEl = document.getElementById('login-error');
  errEl.style.display='none';

  if (!email || !pass) { errEl.style.display='block'; errEl.textContent='Preenche email e password.'; return; }

  try {
    const data = await api('/auth/login', { method:'POST', body:{ email, password: pass } });
    authToken   = data.token;
    currentUser = data.user;
    localStorage.setItem('dm_token', authToken);  // sessão persistente (#17)
    updateAuthNav();
    showToast(`👋 Bem-vindo, ${currentUser.name}!`,'success');
    nav(currentUser.role === 'buyer' ? 'catalog' : 'dashboard');
  } catch(e) {
    errEl.style.display='block';
    errEl.textContent = '✗ ' + e.message;
  }
}

async function doRegister() {
  const name  = document.getElementById('reg-name').value.trim();
  const email = document.getElementById('reg-email').value.trim();
  const pass  = document.getElementById('reg-pass').value;
  const pass2 = document.getElementById('reg-pass2').value;
  const role  = document.querySelector('.role-btn.active')?.id?.replace('role-','') || 'buyer';
  const errEl = document.getElementById('reg-error');
  errEl.style.display='none';

  try {
    await api('/auth/register', { method:'POST', body:{
      name, email, password: pass, password2: pass2, role
    }});
    showToast('✅ Conta criada! Faz login.','success');
    showAuthForm('login');
  } catch(e) {
    errEl.style.display='block';
    errEl.textContent = '✗ ' + e.message;
  }
}

function doLogout() {
  api('/auth/logout', { method:'POST' }).catch(()=>{});
  currentUser = null;
  authToken   = null;
  localStorage.removeItem('dm_token');
  updateAuthNav();
  showToast('Sessão terminada.','success');
  nav('home');
}

function selectRole(r) {
  document.getElementById('role-buyer').classList.toggle('active', r==='buyer');
  document.getElementById('role-seller').classList.toggle('active', r==='seller');
}

function updateAuthNav() {
  const el = document.getElementById('auth-nav');
  if (!el) return;
  if (currentUser) {
    const initials = currentUser.name.split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase();
    const canDash  = currentUser.role==='seller' || currentUser.role==='admin';
    el.innerHTML = `
      ${canDash ? `<button class="btn btn-ghost btn-sm" onclick="nav('dashboard')">💼 Dashboard</button>` : ''}
      <button class="btn btn-ghost btn-sm" onclick="nav('profile')">👤 Perfil</button>
      <div style="width:32px;height:32px;border-radius:50%;background:${currentUser.color||'#7c6cfc'};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;cursor:pointer" onclick="nav('profile')">${initials}</div>
      <button class="btn btn-ghost btn-sm" onclick="doLogout()">Sair</button>`;
  } else {
    el.innerHTML = `<button class="btn btn-primary btn-sm" onclick="nav('login')"><span>Entrar</span> →</button>`;
  }
}

// ──────────────────────────────────────────────────────────
// PERMISSÕES (#19) — verificadas também no servidor
// ──────────────────────────────────────────────────────────
function canAccessDash() { return currentUser && (currentUser.role==='seller'||currentUser.role==='admin'); }

// ──────────────────────────────────────────────────────────
// ROUTING
// ──────────────────────────────────────────────────────────
function nav(viewId) {
  if (viewId==='dashboard' && !canAccessDash()) { showToast('Acesso negado.','error'); return; }
  if ((viewId==='profile'||viewId==='cart'||viewId==='checkout') && !currentUser) {
    showToast('Faz login primeiro.','error'); nav('login'); return;
  }
  prevView = document.querySelector('.view.active')?.id?.replace('view-','') || 'home';
  document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  const el = document.getElementById('view-'+viewId);
  if (!el) return;
  el.classList.add('active');
  window.scrollTo(0,0);

  if (viewId==='home')      renderFeatured();
  if (viewId==='catalog')   filterProducts();
  if (viewId==='cart')      renderCart();
  if (viewId==='dashboard') renderDashboard();
  if (viewId==='profile')   renderProfile();
  if (viewId==='login')     showAuthForm('login');
}
function showView(v){ nav(v); }
function goBack(){ nav(prevView||'home'); }

// ──────────────────────────────────────────────────────────
// DASHBOARD COMPLETO (#20) — KPIs, editar, vendas, admin users
// ──────────────────────────────────────────────────────────
async function renderDashboard() {
  if (!canAccessDash()) return;
  let data;
  try { data = await api('/dashboard'); }
  catch(e) { showToast(e.message,'error'); return; }

  const { kpis, products: myProds, sales, is_admin } = data;

  document.getElementById('dash-title').textContent   = is_admin ? '⚡ Admin Dashboard' : '💼 Dashboard';
  document.getElementById('dash-welcome').textContent = `Olá, ${currentUser.name} · ${currentUser.role.toUpperCase()}`;
  document.getElementById('ds-sales').textContent     = kpis.total_sales;
  document.getElementById('ds-revenue').textContent   = kpis.total_revenue.toFixed(2)+'€';
  document.getElementById('ds-products').textContent  = kpis.total_products;
  document.getElementById('ds-contacts').textContent  = kpis.unique_buyers;
  document.getElementById('ds-prod-count').textContent= `(${myProds.length})`;

  document.getElementById('my-gigs-list').innerHTML = myProds.length ? myProds.map(p => {
    const em = EMOJI[p.category]||'📦';
    return `<div class="gig-item">
      <div style="font-size:1.75rem">${em}</div>
      <div class="gig-item-info">
        <div class="gig-item-title">${p.title}</div>
        <div class="gig-item-meta">
          ${parseFloat(p.price).toFixed(2)}€ · ${p.type}
          · <span style="color:var(--green)">🛒 ${p.actual_sales} vendas</span>
          · <span style="color:var(--accent2)">💰 ${p.actual_revenue.toFixed(2)}€</span>
          ${is_admin ? ` · <b style="color:var(--amber)">${p.seller_name}</b>` : ''}
        </div>
      </div>
      <div class="gig-actions">
        ${p.can_edit ? `<button class="btn btn-ghost btn-sm" onclick="openEditGigForm('${p.id}')">✏️ Editar</button>` : ''}
        ${p.can_edit ? `<button class="btn btn-ghost btn-sm" style="color:var(--rose)" onclick="deleteGig('${p.id}')">🗑 Eliminar</button>` : ''}
      </div>
    </div>`;
  }).join('') : `<div class="empty-state" style="padding:2rem"><div class="big-icon">📦</div><h3>Sem produtos</h3></div>`;

  document.getElementById('sales-history').innerHTML = sales.length
    ? `<table class="sales-table" style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr><th style="text-align:left;padding:8px;color:var(--text3)">Produto</th><th style="text-align:left;padding:8px;color:var(--text3)">Comprador</th><th style="text-align:left;padding:8px;color:var(--text3)">Data</th><th style="text-align:right;padding:8px;color:var(--text3)">Valor</th></tr></thead>
        <tbody>${sales.map(s=>`<tr style="border-top:1px solid var(--border)">
          <td style="padding:8px">${s.product_title}</td>
          <td style="padding:8px"><div>${s.buyer_name}</div><div style="font-size:11px;color:var(--text3)">${s.buyer_email}</div></td>
          <td style="padding:8px;color:var(--text3)">${new Date(s.purchased_at).toLocaleDateString('pt-PT')}</td>
          <td style="padding:8px;text-align:right;color:var(--green);font-weight:700">+${parseFloat(s.amount).toFixed(2)}€</td>
        </tr>`).join('')}</tbody></table>`
    : `<p style="font-size:13px;color:var(--text3);padding:10px">Nenhuma venda registada.</p>`;

  // Admin: gestão de utilizadores
  const usersSection = document.getElementById('admin-users-section');
  if (is_admin) {
    usersSection.style.display = 'block';
    try {
      const users = await api('/dashboard/users');
      document.getElementById('admin-users-table').innerHTML = `
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr><th style="text-align:left;padding:8px;color:var(--text3)">Nome</th><th style="text-align:left;padding:8px;color:var(--text3)">Email</th><th style="text-align:left;padding:8px;color:var(--text3)">Papel</th></tr></thead>
          <tbody>${users.map(u=>`<tr style="border-top:1px solid var(--border)">
            <td style="padding:8px">${u.name}</td>
            <td style="padding:8px;color:var(--text3)">${u.email}</td>
            <td style="padding:8px"><span class="badge-role badge-${u.role}">${u.role.toUpperCase()}</span></td>
          </tr>`).join('')}</tbody></table>`;
    } catch(e) {}
  } else {
    usersSection.style.display = 'none';
  }
}

// ──────────────────────────────────────────────────────────
// CRIAR / EDITAR PRODUTO COM UPLOAD REAL (#16, #23)
// ──────────────────────────────────────────────────────────
function openCreateGigForm() {
  _editingProductId = null;
  _gigSelectedFile  = null;
  document.querySelector('.create-title').textContent = 'Criar Produto / Gig';
  document.getElementById('gig-submit-btn').textContent = 'Publicar Produto →';
  document.getElementById('gig-edit-id').value = '';
  ['gig-title','gig-desc','gig-price'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});
  resetUploadZone();
  nav('create-gig');
}

async function openEditGigForm(id) {
  const p = products.find(x=>x.id===String(id)) || normalizeProduct(await api('/products?id='+id));
  _editingProductId = id;
  _gigSelectedFile  = null;
  document.querySelector('.create-title').textContent = '✏️ Editar Produto';
  document.getElementById('gig-submit-btn').textContent = '💾 Guardar Alterações';
  document.getElementById('gig-edit-id').value = id;
  document.getElementById('gig-title').value = p.title;
  document.getElementById('gig-desc').value  = p.desc;
  document.getElementById('gig-price').value = p.price;
  document.getElementById('gig-cat').value   = p.cat;
  document.getElementById('gig-type').value  = p.type;
  if (p.file) {
    document.getElementById('upload-icon').textContent = '✅';
    document.getElementById('upload-label').textContent = p.file + ' (actual)';
    document.getElementById('upload-status').textContent = 'Carrega novo ficheiro para substituir';
    document.getElementById('upload-zone').classList.add('has-file');
  } else resetUploadZone();
  nav('create-gig');
}

function handleGigFileSelect(inp) { if (inp.files[0]) _gigSelectedFile = inp.files[0]; previewGigFile(); }
function handleGigFileDrop(e) {
  e.preventDefault();
  document.getElementById('upload-zone').classList.remove('drag-over');
  const f = e.dataTransfer?.files[0];
  if (f) { _gigSelectedFile = f; previewGigFile(); }
}
function previewGigFile() {
  if (!_gigSelectedFile) return;
  document.getElementById('upload-icon').textContent='✅';
  document.getElementById('upload-label').textContent=_gigSelectedFile.name;
  document.getElementById('upload-status').textContent=(_gigSelectedFile.size/1024/1024).toFixed(2)+'MB · pronto';
  document.getElementById('upload-zone').classList.add('has-file');
}
function resetUploadZone() {
  _gigSelectedFile = null;
  document.getElementById('upload-icon').textContent='📤';
  document.getElementById('upload-label').textContent='Clica ou arrasta o ficheiro aqui';
  document.getElementById('upload-status').textContent='PDF, ZIP, MP4, EPUB, DOCX — máx. 100MB';
  document.getElementById('upload-zone').classList.remove('has-file','drag-over');
  document.getElementById('gig-file-input').value='';
}

async function submitGigForm() {
  const title = document.getElementById('gig-title').value.trim();
  const desc  = document.getElementById('gig-desc').value.trim();
  const price = document.getElementById('gig-price').value;
  const cat   = document.getElementById('gig-cat').value;
  const type  = document.getElementById('gig-type').value;
  const errBox= document.getElementById('gig-form-errors');
  errBox.style.display='none';

  const fd = new FormData();
  fd.append('title', title);
  fd.append('description', desc);
  fd.append('price', price);
  fd.append('category', cat);
  fd.append('type', type);
  if (_gigSelectedFile) fd.append('file', _gigSelectedFile);

  try {
    if (_editingProductId) {
      await api('/products?id='+_editingProductId, { method:'PUT', body: fd, isFormData: true });
      showToast('✅ Produto actualizado!','success');
    } else {
      await api('/products', { method:'POST', body: fd, isFormData: true });
      showToast('🚀 Produto publicado!','success');
    }
    await loadProducts();
    nav('dashboard');
  } catch(e) {
    errBox.style.display='block';
    errBox.textContent = '⚠ ' + e.message;
  }
}

async function deleteGig(id) {
  if (!confirm('Remover este produto definitivamente?')) return;
  try {
    await api('/products?id='+id, { method:'DELETE' });
    showToast('Produto removido.','success');
    await loadProducts();
    renderDashboard();
  } catch(e) { showToast(e.message,'error'); }
}

// ──────────────────────────────────────────────────────────
// PERFIL + MEUS DOWNLOADS (#16 — persistente na BD)
// ──────────────────────────────────────────────────────────
async function renderProfile() {
  if (!currentUser) { nav('login'); return; }
  let prof;
  try { prof = await api('/profile'); } catch(e) { prof = currentUser; }

  const initials = currentUser.name.split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase();
  document.getElementById('profile-header-content').innerHTML = `
    <div class="avatar-lg" style="background:${currentUser.color||'#7c6cfc'}">${initials}</div>
    <div>
      <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.2rem">${currentUser.name}</div>
      <div style="color:var(--text3);font-size:13px;margin:.25rem 0">${currentUser.email}</div>
      <span class="badge-role badge-${currentUser.role}">${currentUser.role.toUpperCase()}</span>
    </div>`;

  document.getElementById('profile-bio').value   = prof.bio       || '';
  document.getElementById('profile-url').value   = prof.portfolio || '';
  document.getElementById('profile-skill').value = prof.skill     || '';

  try {
    const downloads = await api('/dashboard/downloads');
    const listEl = document.getElementById('my-downloads-list');
    listEl.innerHTML = downloads.length ? downloads.map(d => `
      <div class="file-item" style="margin-bottom:8px">
        <span style="font-size:1.5rem">${EMOJI[d.category]||'📦'}</span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:500;font-size:14px">${d.title}</div>
          <div style="font-size:11px;color:var(--text3)">Adquirido em ${new Date(d.purchased_at).toLocaleDateString('pt-PT')} · ${parseFloat(d.amount).toFixed(2)}€ · ${d.download_count}x descarregado</div>
        </div>
        ${d.type==='digital' ? `<button class="btn btn-primary btn-sm" onclick="downloadProduct('${d.product_id}')">⬇ Re-download</button>` : ''}
      </div>`).join('') : `<p style="color:var(--text3);font-size:13px">Ainda não tens downloads.</p>`;
  } catch(e) {}
}

async function saveProfile() {
  try {
    await api('/profile', { method:'PUT', body:{
      bio:       document.getElementById('profile-bio').value,
      portfolio: document.getElementById('profile-url').value,
      skill:     document.getElementById('profile-skill').value,
    }});
    showToast('✅ Perfil guardado!','success');
  } catch(e) { showToast(e.message,'error'); }
}

// ──────────────────────────────────────────────────────────
// UI HELPERS
// ──────────────────────────────────────────────────────────
function showAuthForm(form) {
  document.getElementById('auth-form-login').style.display    = form==='login'    ? 'block':'none';
  document.getElementById('auth-form-register').style.display = form==='register' ? 'block':'none';
  ['login-error','reg-error'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';});
}

function toggleTheme() {
  const isDark = !document.documentElement.hasAttribute('data-theme');
  if (isDark) document.documentElement.setAttribute('data-theme','light');
  else document.documentElement.removeAttribute('data-theme');
  document.querySelector('.theme-btn').textContent = isDark ? '🌙' : '☀️';
  localStorage.setItem('dm_theme', isDark ? 'light':'dark');
}

function showToast(msg, type='success') {
  const colors={success:'var(--green)',warning:'var(--amber)',error:'var(--rose)'};
  const icons ={success:'●',warning:'▲',error:'◆'};
  const el=document.createElement('div');
  el.className='toast';
  el.innerHTML=`<span style="color:${colors[type]||colors.success};font-size:16px">${icons[type]||'●'}</span><span>${msg}</span>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(()=>el.remove(),3500);
}
