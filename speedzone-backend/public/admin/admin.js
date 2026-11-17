const $ = s => document.querySelector(s), app = $('#app'), dlg = $('#dlg');
const STATUS = ['aguardando_pagamento', 'pago', 'em_separacao', 'enviado', 'pronto_retirada', 'entregue', 'cancelado'];
let tab = 'dash';

async function init() {
  const me = await api('GET', '/auth/me');
  if (!me.autenticado || me.usuario.perfil !== 'admin') return login();
  $('#top').innerHTML = `<span class="mut">${esc(me.usuario.nome)}</span> <a href="../loja/index.html">Ver loja</a> <a href="#" id="sair" class="ico-link">${ic('logout')}Sair</a>`;
  $('#sair').onclick = async e => { e.preventDefault(); await api('POST', '/auth/logout'); csrf = null; init(); };
  app.innerHTML = `<div class="tabs">${[['dash', 'Dashboard'], ['prod', 'Produtos'], ['ped', 'Pedidos'], ['cli', 'Clientes']].map(([k, n]) => `<button class="btn ghost sm" data-t="${k}">${n}</button>`).join('')}</div><div id="m"></div>`;
  app.querySelectorAll('[data-t]').forEach(b => b.onclick = () => show(b.dataset.t)); show(tab);
}
function login() {
  $('#top').innerHTML = '';
  app.innerHTML = '<form class="card login" id="f"><h2>Acesso restrito</h2><input name="email" type="email" placeholder="E-mail" required><input name="senha" type="password" placeholder="Senha" required><button class="btn">' + ic('login') + 'Entrar</button></form>';
  $('#f').onsubmit = async e => { e.preventDefault(); try { const d = await api('POST', '/auth/login', Object.fromEntries(new FormData(e.target))); if (d.usuario.perfil !== 'admin') { await api('POST', '/auth/logout'); csrf = null; return toast('Esta conta não é de administrador.'); } init(); } catch (x) { toast(x.message); } };
}
function show(t) { tab = t; app.querySelectorAll('[data-t]').forEach(b => b.classList.toggle('on', b.dataset.t === t)); ({ dash, prod, ped, cli })[t](); }
const $m = () => $('#m');
const dt = s => new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });

// ---------- Dashboard ----------
async function dash() {
  const d = await api('GET', '/admin/dashboard'), max = Math.max(1, ...d.vendas_ultimos_7_dias.map(x => x.valor));
  const k = (t, v) => `<div class="card kpi"><span class="mut">${t}</span><b>${v}</b></div>`;
  $m().innerHTML = `<div class="kpis">${k('Faturamento do mês', R(d.faturamento_mes))}${k('Pedidos pagos no mês', d.pedidos_pagos_mes)}${k('Ticket médio', R(d.ticket_medio_mes))}${k('Pedidos hoje', d.pedidos_hoje)}${k('Aguardando pagamento', d.pedidos_por_status.aguardando_pagamento)}${k('Oficinas pendentes', d.oficinas_pendentes)}</div>
  <div class="card"><h3>Vendas dos últimos 7 dias</h3><div class="chart">${L(d.vendas_ultimos_7_dias, x => `<i style="height:${Math.round(x.valor / max * 100)}%" title="${x.dia}: ${R(x.valor)}"></i>`)}</div></div>
  <div class="two" style="grid-template-columns:1fr 1fr"><div class="card"><h3>Estoque baixo</h3>${d.estoque_baixo.length ? `<table>${L(d.estoque_baixo, p => `<tr><td>${esc(p.nome)}</td><td class="err">${p.estoque} un.</td></tr>`)}</table>` : '<p class="mut">Tudo em ordem.</p>'}</div>
  <div class="card"><h3>Mais vendidos (30 dias)</h3>${d.mais_vendidos_30d.length ? `<table>${L(d.mais_vendidos_30d, p => `<tr><td>${esc(p.nome)}</td><td>${p.quantidade} un.</td><td>${R(p.receita)}</td></tr>`)}</table>` : '<p class="mut">Sem vendas ainda.</p>'}</div></div>`;
}

// ---------- Produtos ----------
async function prod() {
  $m().innerHTML = '<div class="row"><input id="q" placeholder="Buscar por nome, SKU ou código"><select id="fb" style="width:auto"><option value="">Todos</option><option value="1">Estoque baixo</option></select><button class="btn" id="novo">+ Novo produto</button></div><div id="tb"></div>';
  const lista = async () => { const d = await api('GET', `/admin/produtos?por_pagina=50&busca=${encodeURIComponent($('#q').value)}${$('#fb').value ? '&estoque_baixo=1' : ''}`);
    $('#tb').innerHTML = `<table><tr><th>SKU</th><th>Produto</th><th>Preço</th><th>Estoque</th><th>Status</th><th></th></tr>${L(d, p => `<tr><td>${esc(p.sku)}</td><td>${esc(p.nome)}</td><td>${R(p.preco_final)}</td><td ${p.estoque <= 2 ? 'class="err"' : ''}>${p.estoque}</td><td>${p.ativo ? 'Ativo' : '<span class="mut">Inativo</span>'}</td>
    <td><button class="btn ghost sm" data-e="${p.id}">${ic('edit')}Editar</button> <button class="btn ghost sm" data-s="${p.id}">${ic('plus')}Estoque</button> <button class="btn red sm" data-x="${p.id}">${ic('trash')}Excluir</button></td></tr>`)}</table>`;
    $('#tb').querySelectorAll('[data-e]').forEach(b => b.onclick = () => form(b.dataset.e, lista));
    $('#tb').querySelectorAll('[data-s]').forEach(b => b.onclick = async () => { const q = parseInt(prompt('Quantas unidades entraram no estoque?'), 10); if (q > 0) { try { await api('POST', `/admin/produtos/${b.dataset.s}/estoque`, { tipo: 'entrada', quantidade: q, motivo: 'Entrada pelo painel' }); lista(); } catch (e) { toast(e.message); } } });
    $('#tb').querySelectorAll('[data-x]').forEach(b => b.onclick = async () => { if (confirm('Excluir este produto?')) { try { toast((await api('DELETE', '/admin/produtos/' + b.dataset.x)).mensagem); lista(); } catch (e) { toast(e.message); } } });
  };
  $('#q').onchange = lista; $('#fb').onchange = lista; $('#novo').onclick = () => form(null, lista); lista();
}
async function form(id, depois) {
  const [cats, marcas] = await Promise.all([api('GET', '/admin/categorias'), api('GET', '/marcas')]), p = id ? await api('GET', '/admin/produtos/' + id) : {};
  const i = (n, ph, t = 'text', v = p[n]) => `<input name="${n}" type="${t}" placeholder="${ph}" value="${esc(v ?? '')}" ${t === 'number' ? 'step="any"' : ''}>`;
  dlg.innerHTML = `<form id="pf"><h2>${id ? 'Editar' : 'Novo'} produto</h2>${i('sku', 'SKU')}${i('nome', 'Nome do produto')}<div class="row"><select name="categoria_id">${L(cats, c => `<option value="${c.id}" ${p.categoria?.id === c.id ? 'selected' : ''}>${c.parent_id ? '— ' : ''}${esc(c.nome)}</option>`)}</select>
  <select name="marca_id"><option value="">Sem marca</option>${L(marcas, m => `<option value="${m.id}" ${p.marca?.id === m.id ? 'selected' : ''}>${esc(m.nome)}</option>`)}</select></div>
  <div class="row">${i('preco', 'Preço (R$)', 'number')}${i('preco_promocional', 'Promocional (opcional)', 'number')}${id ? '' : i('estoque', 'Estoque inicial', 'number')}${i('estoque_minimo', 'Estoque mínimo', 'number')}</div>
  <div class="row">${i('codigo_fabricante', 'Código do fabricante')}${i('peso_kg', 'Peso (kg)', 'number')}</div><textarea name="descricao" placeholder="Descrição">${esc(p.descricao || '')}</textarea>
  <label><input type="checkbox" name="destaque" ${p.destaque ? 'checked' : ''}>Destaque</label> <label><input type="checkbox" name="universal" ${p.universal ? 'checked' : ''}>Peça universal</label> <label><input type="checkbox" name="ativo" ${p.ativo === false ? '' : 'checked'}>Ativo</label>
  <p class="mut">Foto principal: <input type="file" name="foto" accept="image/jpeg,image/png,image/webp"></p><div class="row"><button class="btn">Salvar</button><button type="button" class="btn ghost" id="fc">Cancelar</button></div></form>`;
  dlg.showModal(); $('#fc').onclick = () => dlg.close();
  $('#pf').onsubmit = async e => {
    e.preventDefault(); const f = e.target, v = n => f[n]?.value.trim();
    const b = { sku: v('sku'), nome: v('nome'), categoria_id: +v('categoria_id'), marca_id: v('marca_id') ? +v('marca_id') : null, preco: v('preco'), preco_promocional: v('preco_promocional') || null, estoque_minimo: v('estoque_minimo') || undefined,
      codigo_fabricante: v('codigo_fabricante') || null, peso_kg: v('peso_kg') || undefined, descricao: v('descricao') || null, destaque: f.destaque.checked, universal: f.universal.checked, ativo: f.ativo.checked };
    if (!id && v('estoque')) b.estoque = v('estoque');
    try {
      const r = id ? await api('PUT', '/admin/produtos/' + id, b) : await api('POST', '/admin/produtos', b);
      if (f.foto.files[0]) { const fd = new FormData(); fd.append('imagem', f.foto.files[0]); fd.append('principal', '1'); await api('POST', `/admin/produtos/${r.id}/imagens`, fd); }
      dlg.close(); toast('Produto salvo'); depois();
    } catch (x) { toast(x.message); }
  };
}

// ---------- Pedidos ----------
async function ped() {
  $m().innerHTML = `<div class="row"><select id="st" style="width:auto"><option value="">Todos os status</option>${L(STATUS, s => `<option>${s}</option>`)}</select><input id="q" placeholder="Código, nome ou e-mail do cliente"></div><div id="tb"></div>`;
  const lista = async () => { const d = await api('GET', `/admin/pedidos?por_pagina=50&status=${$('#st').value}&busca=${encodeURIComponent($('#q').value)}`);
    $('#tb').innerHTML = `<table><tr><th>Código</th><th>Data</th><th>Cliente</th><th>Total</th><th>Status</th><th></th></tr>${L(d, p => `<tr><td>${esc(p.codigo)}</td><td>${dt(p.criado_em)}</td><td>${esc(p.cliente.nome)}</td><td>${R(p.total)}</td><td><span class="tag">${esc(p.status_rotulo)}</span></td><td><button class="btn ghost sm" data-o="${p.id}">Abrir</button></td></tr>`)}</table>`;
    $('#tb').querySelectorAll('[data-o]').forEach(b => b.onclick = () => abrir(b.dataset.o, lista));
  };
  $('#st').onchange = lista; $('#q').onchange = lista; lista();
}
async function abrir(id, depois) {
  const p = await api('GET', '/admin/pedidos/' + id);
  dlg.innerHTML = `<h2>${esc(p.codigo)} <span class="tag">${esc(p.status_rotulo)}</span></h2><p>${esc(p.cliente.nome)} · ${esc(p.cliente.email)} · ${esc(p.cliente.telefone || '')}${p.cliente.tipo_cliente === 'oficina' ? ' · <em>Oficina</em>' : ''}</p>
  <p class="mut">${p.tipo_entrega === 'entrega' ? `Entrega: ${esc(p.endereco_entrega.logradouro)}, ${esc(p.endereco_entrega.numero)} – ${esc(p.endereco_entrega.bairro)}, ${esc(p.endereco_entrega.cidade)}/${p.endereco_entrega.uf} · CEP ${p.endereco_entrega.cep}` : 'Retirada na loja'} · Pagamento: ${esc(p.forma_pagamento)}${p.observacao ? ' · Obs.: ' + esc(p.observacao) : ''}</p>
  <table>${L(p.itens, i => `<tr><td>${esc(i.nome)}</td><td>${i.quantidade} × ${R(i.preco_unitario)}</td><td>${R(i.subtotal)}</td></tr>`)}</table>
  <p>Subtotal ${R(p.subtotal)} · Desconto ${R(p.desconto)} · Frete ${R(p.frete)} · <b>Total ${R(p.total)}</b></p>
  <h3>Histórico</h3><p class="mut">${L(p.historico, h => `${dt(h.criado_em)} – ${esc(h.status_rotulo)}${h.observacao ? ' (' + esc(h.observacao) + ')' : ''}<br>`)}</p>
  <div class="row">${L(p.proximos_status, s => `<button class="btn ${s === 'cancelado' ? 'red' : ''} sm" data-s="${s}">${ic('right')}${esc(s.replace('_', ' '))}</button>`)}<button class="btn ghost sm" id="fc">Fechar</button></div>`;
  dlg.showModal(); $('#fc').onclick = () => dlg.close();
  dlg.querySelectorAll('[data-s]').forEach(b => b.onclick = async () => {
    const s = b.dataset.s, body = { status: s };
    if (s === 'enviado') body.codigo_rastreio = prompt('Código de rastreio:') || undefined; if (s === 'cancelado' && !confirm('Cancelar e devolver ao estoque?')) return;
    try { await api('PATCH', `/admin/pedidos/${id}/status`, body); dlg.close(); depois(); } catch (e) { toast(e.message); }
  });
}

// ---------- Clientes ----------
async function cli() {
  $m().innerHTML = '<div class="row"><input id="q" placeholder="Buscar cliente"><label><input type="checkbox" id="op">Só oficinas pendentes</label></div><div id="tb"></div>';
  const lista = async () => { const d = await api('GET', `/admin/usuarios?por_pagina=50&busca=${encodeURIComponent($('#q').value)}${$('#op').checked ? '&oficina_pendente=1' : ''}`);
    $('#tb').innerHTML = `<table><tr><th>Nome</th><th>E-mail</th><th>Tipo</th><th>Pedidos</th><th></th></tr>${L(d, u => `<tr><td>${esc(u.nome)}${u.empresa ? '<br><small class="mut">' + esc(u.empresa) + '</small>' : ''}</td><td>${esc(u.email)}</td><td>${u.tipo_cliente === 'oficina' ? (u.oficina_aprovada ? 'Oficina ' + ic('check') : '<span class="err">Oficina pendente</span>') : 'PF'}</td><td>${u.total_pedidos}</td>
    <td>${u.tipo_cliente === 'oficina' && !u.oficina_aprovada ? `<button class="btn sm" data-a="${u.id}">Aprovar</button> ` : ''}${u.perfil === 'cliente' ? `<button class="btn ghost sm" data-t="${u.id}" data-v="${u.ativo ? 0 : 1}">${u.ativo ? 'Desativar' : 'Reativar'}</button>` : ''}</td></tr>`)}</table>`;
    $('#tb').querySelectorAll('[data-a]').forEach(b => b.onclick = async () => { await api('PATCH', '/admin/usuarios/' + b.dataset.a, { oficina_aprovada: true }); lista(); });
    $('#tb').querySelectorAll('[data-t]').forEach(b => b.onclick = async () => { await api('PATCH', '/admin/usuarios/' + b.dataset.t, { ativo: b.dataset.v === '1' }); lista(); });
  };
  $('#q').onchange = lista; $('#op').onchange = lista; lista();
}
init();
