const $ = s => document.querySelector(s), app = $('#app');
let user = null, cart = null, ret = '#/', F = { busca: '', categoria: '', ordem: 'relevancia', veiculo_id: '', pagina: 1 };
const go = h => location.hash = h;
const img = p => p.imagem ? `<img src="${esc(p.imagem)}" alt="${esc(p.nome)}" loading="lazy">` : '<div class="ph">S</div>';
const card = p => `<a class="card prod" href="#/produto/${esc(p.slug)}">${img(p)}<small class="mut">${esc(p.marca ? p.marca.nome : p.categoria.nome)}</small><h3>${esc(p.nome)}</h3>${p.em_promocao ? `<s>${R(p.preco)}</s> <em>-${p.desconto_percentual}%</em><br>` : ''}<strong>${R(p.preco_final)}</strong> ${p.em_estoque ? '' : '<span class="tag">Sem estoque</span>'}</a>`;

async function refresh() {
  const me = await api('GET', '/auth/me'); user = me.autenticado ? me.usuario : null;
  cart = await api('GET', '/carrinho'); $('#cnt').textContent = cart.quantidade_total;
  $('#acc').innerHTML = user ? `${user.perfil === 'admin' ? '<a href="../admin/index.html" class="btn sm ghost">' + ic('shield') + 'Painel admin</a> ' : ''}<a href="#/pedidos" class="ico-link">${ic('user')}Olá, ${esc(user.nome.split(' ')[0])}</a> · <a href="#" id="sair" class="ico-link">${ic('logout')}Sair</a>` : '<a href="#/entrar" class="ico-link">' + ic('login') + 'Entrar</a> <a href="#/cadastro" class="btn sm ghost">Criar conta</a>';
  if ($('#sair')) $('#sair').onclick = async e => { e.preventDefault(); await api('POST', '/auth/logout'); csrf = null; await refresh(); go('#/'); };
}
async function add(id, q = 1) { try { cart = await api('POST', '/carrinho/itens', { produto_id: id, quantidade: q }); $('#cnt').textContent = cart.quantidade_total; toast('Adicionado ao carrinho'); } catch (e) { toast(e.message); } }

// ---------- Seletor de veículo ("Qual é o seu carro?") ----------
const garagemHTML = m => `<select id="vm"><option value="">Montadora</option>${L(m, x => `<option>${esc(x)}</option>`)}</select><select id="vmo" disabled><option value="">Modelo</option></select><select id="vv" disabled><option value="">Ano / motor</option></select>`;
function garagem(aoEscolher) {
  $('#vm').onchange = async e => { F.veiculo_id = ''; $('#vv').disabled = true; const m = await api('GET', '/veiculos/modelos?montadora=' + encodeURIComponent(e.target.value)); $('#vmo').innerHTML = '<option value="">Modelo</option>' + L(m, x => `<option>${esc(x)}</option>`); $('#vmo').disabled = !e.target.value; };
  $('#vmo').onchange = async e => { const v = await api('GET', `/veiculos?montadora=${encodeURIComponent($('#vm').value)}&modelo=${encodeURIComponent(e.target.value)}`); $('#vv').innerHTML = '<option value="">Ano / motor</option>' + L(v, x => `<option value="${x.id}">${x.ano_inicio}–${x.ano_fim || 'atual'} ${esc(x.motor || '')}</option>`); $('#vv').disabled = false; };
  $('#vv').onchange = e => { F.veiculo_id = e.target.value; F.pagina = 1; aoEscolher(); };
}

// ---------- Página inicial ----------
async function home() {
  const [cats, mont, dest] = await Promise.all([api('GET', '/categorias'), api('GET', '/veiculos/montadoras'), api('GET', '/produtos?destaque=1&por_pagina=8')]);
  app.innerHTML = `<section class="hero"><div><h1>Mais que autopeças,<br>é <b>performance</b> em cada detalhe.</h1><p class="mut">Peças para o seu carro ou para a sua oficina, com entrega ou retirada na loja.</p><a class="btn" href="#/produtos">Ver todos os produtos ${ic('right')}</a></div>
  <div class="card"><strong>Qual é o seu carro?</strong><p class="mut">Veja só as peças que servem nele.</p>${garagemHTML(mont)}</div></section>
  <h2>Categorias</h2><div class="grid">${L(cats, c => `<a class="card prod" href="#/produtos?categoria=${esc(c.slug)}"><h3>${esc(c.nome)}</h3><small class="mut">${esc(c.filhas.map(f => f.nome).join(' · '))}</small></a>`)}</div>
  <h2 style="margin-top:28px">Destaques</h2><div class="grid">${L(dest, card)}</div><p class="pager"><a class="btn" href="#/produtos">Ver todos os produtos ${ic('right')}</a></p>`;
  garagem(() => go('#/produtos'));
}

// ---------- Lista de produtos (catálogo completo) ----------
async function catalogo() {
  const [cats, mont] = await Promise.all([api('GET', '/categorias'), api('GET', '/veiculos/montadoras')]);
  const opt = (c, pre = '') => `<option value="${esc(c.slug)}" ${F.categoria === c.slug ? 'selected' : ''}>${pre}${esc(c.nome)}</option>`;
  app.innerHTML = `<h1 style="margin-top:24px">Produtos</h1><div class="card"><strong>Qual é o seu carro?</strong>${garagemHTML(mont)}</div>
  <div class="bar"><input id="q" placeholder="Buscar peça, SKU ou código do fabricante" value="${esc(F.busca)}"><select id="cat"><option value="">Todas as categorias</option>${L(cats, c => opt(c) + L(c.filhas, f => opt(f, '— ')))}</select>
  <select id="ord"><option value="relevancia">Relevância</option><option value="menor_preco">Menor preço</option><option value="maior_preco">Maior preço</option><option value="recentes">Mais recentes</option></select></div><div id="lista"></div>`;
  $('#ord').value = F.ordem;
  $('#q').onchange = e => { F.busca = e.target.value; F.pagina = 1; load(); };
  $('#cat').onchange = e => { F.categoria = e.target.value; F.pagina = 1; load(); };
  $('#ord').onchange = e => { F.ordem = e.target.value; load(); };
  garagem(load); load();
}
async function load() {
  const q = new URLSearchParams({ ordem: F.ordem, pagina: F.pagina, por_pagina: 12 });
  ['busca', 'categoria', 'veiculo_id'].forEach(k => F[k] && q.set(k, F[k]));
  const d = await api('GET', '/produtos?' + q), pg = api.pag;
  $('#lista').innerHTML = (F.veiculo_id ? '<p><span class="tag">Peças compatíveis com o seu carro</span></p>' : '') +
    (d.length ? `<div class="grid">${L(d, card)}</div>` : '<p class="mut">Nenhuma peça encontrada.</p>') +
    (pg.total_paginas > 1 ? `<div class="pager"><button class="btn ghost sm" id="pv" ${F.pagina < 2 ? 'disabled' : ''} aria-label="Página anterior">${ic('chevl')}</button> Página ${pg.pagina} de ${pg.total_paginas} <button class="btn ghost sm" id="nx" ${F.pagina >= pg.total_paginas ? 'disabled' : ''} aria-label="Próxima página">${ic('chevr')}</button></div>` : '');
  if ($('#pv')) { $('#pv').onclick = () => { F.pagina--; load(); }; $('#nx').onclick = () => { F.pagina++; load(); }; }
}

// ---------- Produto ----------
async function produto(slug) {
  const p = await api('GET', '/produtos/' + slug), foto = p.imagens.length ? { imagem: p.imagens[0].url, nome: p.nome } : p;
  app.innerHTML = `<div class="det"><div>${img(foto)}</div><div><small class="mut">${esc(p.categoria.nome)}${p.marca ? ' · ' + esc(p.marca.nome) : ''}</small><h1>${esc(p.nome)}</h1>
  <p class="mut">SKU ${esc(p.sku)}${p.codigo_fabricante ? ' · Cód. fabricante ' + esc(p.codigo_fabricante) : ''}</p>
  ${p.em_promocao ? `<s class="mut">${R(p.preco)}</s> <em>-${p.desconto_percentual}%</em><br>` : ''}<div class="price">${R(p.preco_final)}</div>
  ${p.ultimas_unidades ? `<p class="err">Últimas ${p.estoque} unidades!</p>` : ''}<p>${esc(p.descricao || '')}</p>
  <div class="row"><input id="qtd" type="number" min="1" max="99" value="1" style="width:90px"><button class="btn" id="add" ${p.em_estoque ? '' : 'disabled'}>${p.em_estoque ? ic('cart') + 'Adicionar ao carrinho' : 'Indisponível'}</button></div>
  <div class="row"><input id="cep" placeholder="Seu CEP"><button class="btn ghost" id="fr">${ic('truck')}Calcular frete</button></div><p id="frr" class="mut"></p>
  ${Object.keys(p.especificacoes).length ? `<h3>Especificações</h3><table>${L(Object.entries(p.especificacoes), ([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`)}</table>` : ''}
  <h3>Compatibilidade</h3>${p.universal ? '<p>Peça universal: serve em qualquer veículo.</p>' : ''}<p class="mut">${p.veiculos_compativeis.map(v => `${esc(v.montadora)} ${esc(v.modelo)} ${v.ano_inicio}–${v.ano_fim || 'atual'} ${esc(v.motor || '')}`).join(' · ') || (p.universal ? '' : 'Consulte-nos.')}</p></div></div>
  ${p.relacionados.length ? `<h2>Você também pode gostar</h2><div class="grid">${L(p.relacionados, card)}</div>` : ''}`;
  $('#add').onclick = () => add(p.id, +$('#qtd').value || 1);
  $('#fr').onclick = async () => { try { const f = await api('POST', '/frete/calcular', { cep: $('#cep').value, produto_id: p.id, quantidade: +$('#qtd').value || 1 }); $('#frr').innerHTML = L(f.opcoes, o => `${esc(o.nome)}: <b>${o.gratis ? 'Grátis' : R(o.valor)}</b>${o.tipo === 'entrega' ? ` · ${o.prazo_dias} dia(s) úteis` : ''}<br>`); } catch (e) { $('#frr').textContent = e.message; } };
}

// ---------- Carrinho ----------
async function carrinho() {
  cart = await api('GET', '/carrinho'); $('#cnt').textContent = cart.quantidade_total;
  if (!cart.itens.length) { app.innerHTML = '<h1>Carrinho</h1><div class="card"><p class="mut">Seu carrinho está vazio.</p><a class="btn" href="#/produtos">Ver peças</a></div>'; return; }
  const linha = i => `<div class="card cart-item${i.problema ? ' bad' : ''}">
    <div class="ci-info"><a href="#/produto/${esc(i.slug || '')}"><b>${esc(i.nome)}</b></a>
      <small class="mut">${i.sku ? 'SKU ' + esc(i.sku) + ' · ' : ''}${i.preco_unitario != null ? R(i.preco_unitario) + ' cada' : ''}</small>
      ${i.problema ? `<small class="err">${esc(i.problema)}</small>` : ''}</div>
    <div class="qty"><button class="btn ghost sm" data-menos="${i.produto_id}" data-q="${i.quantidade}" aria-label="Diminuir">${ic('minus')}</button><input type="number" min="1" max="99" value="${i.quantidade}" data-q="${i.produto_id}" aria-label="Quantidade"><button class="btn ghost sm" data-mais="${i.produto_id}" data-q="${i.quantidade}" aria-label="Aumentar">${ic('plus')}</button></div>
    <strong class="ci-total">${i.subtotal != null ? R(i.subtotal) : ''}</strong>
    <button class="btn ghost sm" data-rm="${i.produto_id}" aria-label="Remover" title="Remover">${ic('trash')}</button></div>`;
  const meta = cart.frete_gratis_acima, falta = cart.falta_para_frete_gratis, pct = meta > 0 ? Math.min(100, Math.round((1 - falta / meta) * 100)) : 100;
  app.innerHTML = `<h1>Carrinho <small class="mut">(${cart.quantidade_total} ${cart.quantidade_total === 1 ? 'item' : 'itens'})</small></h1>
  <div class="two"><div>${L(cart.itens, linha)}<p><button class="btn ghost sm" id="limpar">${ic('trash')}Limpar carrinho</button> <a class="btn ghost sm" href="#/produtos">${ic('left')}Continuar comprando</a></p></div>
  <aside class="card"><h3>Resumo</h3><p>Produtos: <b>${R(cart.subtotal)}</b></p>${cart.desconto_oficina ? `<p>Desconto oficina (${cart.desconto_oficina_percentual}%): <b>−${R(cart.desconto_oficina)}</b></p>` : ''}
  <p>Total dos produtos: <strong class="price">${R(cart.total_produtos)}</strong></p>
  <div class="bar-frete"><i style="width:${pct}%"></i></div>
  <p class="mut">${falta > 0 ? `${ic('truck')} Faltam ${R(falta)} para frete grátis.` : ic('truck') + ' Você ganhou frete grátis!'}</p>
  ${cart.pode_finalizar ? '' : '<p class="err">Corrija os itens com aviso para finalizar.</p>'}
  <a class="btn" href="#/checkout" style="width:100%;text-align:center${cart.pode_finalizar ? '' : ';opacity:.4;pointer-events:none'}">Finalizar compra ${ic('right')}</a></aside></div>`;
  const atualizar = async (id, q) => { try { await api('PUT', '/carrinho/itens/' + id, { quantidade: q }); } catch (e) { toast(e.message); } carrinho(); };
  app.querySelectorAll('input[data-q]').forEach(el => el.onchange = () => atualizar(el.dataset.q, Math.max(0, +el.value || 0)));
  app.querySelectorAll('[data-menos]').forEach(b => b.onclick = () => atualizar(b.dataset.menos, +b.dataset.q - 1));
  app.querySelectorAll('[data-mais]').forEach(b => b.onclick = () => atualizar(b.dataset.mais, +b.dataset.q + 1));
  app.querySelectorAll('[data-rm]').forEach(b => b.onclick = async () => { await api('DELETE', '/carrinho/itens/' + b.dataset.rm); carrinho(); });
  $('#limpar').onclick = async () => { await api('DELETE', '/carrinho'); carrinho(); };
}

// ---------- Login e cadastro (páginas separadas) ----------
const enviar = (seletor, rota) => { const f = $(seletor); f.onsubmit = async e => { e.preventDefault(); try { await api('POST', rota, Object.fromEntries(new FormData(f))); await refresh(); go(ret); ret = '#/'; } catch (x) { toast(x.message); } }; };
function entrar() {
  app.innerHTML = `<form id="fl" class="card auth"><h2>Entrar</h2><input name="email" type="email" placeholder="E-mail" autocomplete="email" required><input name="senha" type="password" placeholder="Senha" autocomplete="current-password" required><button class="btn" style="width:100%">Entrar</button>
  <p class="mut">Ainda não tem conta? <a href="#/cadastro"><b>Criar conta</b></a></p></form>`;
  enviar('#fl', '/auth/login');
}
function cadastro() {
  app.innerHTML = `<form id="fc" class="card auth"><h2>Criar conta</h2><select name="tipo_cliente"><option value="pf">Pessoa física</option><option value="oficina">Oficina / empresa (desconto após aprovação)</option></select>
  <input name="nome" placeholder="Nome completo" required><input name="empresa" placeholder="Razão social ou nome da oficina"><input name="documento" required><input name="telefone" placeholder="Telefone com DDD">
  <input name="email" type="email" placeholder="E-mail" autocomplete="email" required><input name="senha" type="password" minlength="8" placeholder="Senha (mín. 8 caracteres)" autocomplete="new-password" required><button class="btn" style="width:100%">Cadastrar</button>
  <p class="mut">Já tem conta? <a href="#/entrar"><b>Entrar</b></a></p></form>`;
  const f = $('#fc'), t = () => { const o = f.tipo_cliente.value === 'oficina'; f.empresa.style.display = o ? '' : 'none'; f.documento.placeholder = o ? 'CNPJ' : 'CPF'; };
  f.tipo_cliente.onchange = t; t(); enviar('#fc', '/auth/cadastro');
}

// ---------- Checkout ----------
async function checkout() {
  if (!user) { ret = '#/checkout'; return go('#/entrar'); }
  cart = await api('GET', '/carrinho'); if (!cart.pode_finalizar) { toast('Revise seu carrinho.'); return go('#/carrinho'); }
  const ends = await api('GET', '/conta/enderecos'), campos = ['destinatario', 'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf'];
  app.innerHTML = `<h1>Finalizar compra</h1><div class="two"><form id="fk" class="card"><h3>Entrega</h3>
  <label><input type="radio" name="tipo_entrega" value="entrega" checked>Entregar no meu endereço</label><br><label><input type="radio" name="tipo_entrega" value="retirada">Retirar na loja (grátis)</label>
  <div id="endbox" style="margin-top:10px">${L(ends, e => `<label class="card" style="display:block"><input type="radio" name="endereco_id" value="${e.id}" data-cep="${e.cep}" ${e.padrao ? 'checked' : ''}>${esc(e.logradouro)}, ${esc(e.numero)} · ${esc(e.cidade)}/${e.uf} · CEP ${e.cep}</label>`)}
  <details ${ends.length ? '' : 'open'}><summary>Cadastrar novo endereço</summary>${L(campos, n => `<input data-n="${n}" placeholder="${n[0].toUpperCase() + n.slice(1)}${n === 'uf' ? ' (ex.: PR)' : ''}">`)}</details></div>
  <h3>Pagamento</h3><label><input type="radio" name="forma_pagamento" value="pix" checked>Pix</label> <label><input type="radio" name="forma_pagamento" value="boleto">Boleto</label> <label><input type="radio" name="forma_pagamento" value="cartao">Cartão</label>
  <textarea name="observacao" placeholder="Observações (opcional)" style="margin-top:10px"></textarea><button class="btn">${ic('check')}Confirmar pedido</button></form><aside class="card" id="res"></aside></div>`;
  const f = $('#fk'), val = n => f.querySelector(`[name=${n}]:checked`)?.value;
  async function resumo() {
    let frete = 0, txt = '';
    if (val('tipo_entrega') === 'entrega') { const r = f.querySelector('[name=endereco_id]:checked'); if (r) { try { const q = await api('POST', '/frete/calcular', { cep: r.dataset.cep }); frete = q.opcoes[0].valor; txt = `${q.opcoes[0].prazo_dias} dia(s) úteis`; } catch (e) { txt = e.message; } } else txt = 'calculado ao confirmar'; }
    $('#res').innerHTML = `<h3>Resumo</h3><p>Produtos: <b>${R(cart.subtotal)}</b></p>${cart.desconto_oficina ? `<p>Desconto oficina: <b>−${R(cart.desconto_oficina)}</b></p>` : ''}<p>Frete: <b>${frete ? R(frete) : 'Grátis / a calcular'}</b> <small class="mut">${esc(txt)}</small></p>`;
  }
  f.onchange = resumo; resumo();
  f.onsubmit = async e => {
    e.preventDefault(); const b = { tipo_entrega: val('tipo_entrega'), forma_pagamento: val('forma_pagamento'), observacao: f.observacao.value };
    try {
      if (b.tipo_entrega === 'entrega') {
        const novo = Object.fromEntries(campos.map(n => [n, f.querySelector(`[data-n=${n}]`).value])); 
        b.endereco_id = novo.destinatario || novo.cep ? (await api('POST', '/conta/enderecos', { ...novo, padrao: true })).id : val('endereco_id');
      }
      const p = await api('POST', '/pedidos', b); await refresh();
      app.innerHTML = `<div class="card"><h1>Pedido <b>${esc(p.codigo)}</b> recebido!</h1><p>Total: <b>${R(p.total)}</b> · ${esc(p.status_rotulo)}</p><p class="mut">Assim que o pagamento for confirmado pela loja, você acompanha a separação e o envio em "Meus pedidos".</p><a class="btn" href="#/pedidos">Meus pedidos</a></div>`;
    } catch (x) { toast(x.message); }
  };
}

// ---------- Meus pedidos ----------
async function pedidos() {
  if (!user) { ret = '#/pedidos'; return go('#/entrar'); }
  const d = await api('GET', '/pedidos?por_pagina=30');
  app.innerHTML = '<h1>Meus pedidos</h1>' + (d.length ? L(d, p => `<div class="card"><b>${esc(p.codigo)}</b> <span class="tag">${esc(p.status_rotulo)}</span> <span class="mut">${new Date(p.criado_em.replace(' ', 'T')).toLocaleDateString('pt-BR')} · ${p.quantidade_itens} item(ns)</span> · <strong>${R(p.total)}</strong>
  ${p.codigo_rastreio ? `<br>Rastreio: ${esc(p.codigo_rastreio)}` : ''} ${p.status === 'aguardando_pagamento' ? `<button class="btn red sm" data-c="${esc(p.codigo)}">Cancelar</button>` : ''}</div>`) : '<p class="mut">Você ainda não fez pedidos.</p>');
  app.querySelectorAll('[data-c]').forEach(b => b.onclick = async () => { if (confirm('Cancelar este pedido?')) { try { await api('POST', `/pedidos/${b.dataset.c}/cancelar`); } catch (e) { toast(e.message); } pedidos(); } });
}

async function route() {
  const [path, qs] = (location.hash || '#/').slice(1).split('?'), [, r, a] = path.split('/');
  try {
    if (r === 'produto') await produto(a);
    else if (r === 'produtos') { if (qs) { const q = new URLSearchParams(qs); F = { ...F, busca: q.get('busca') || '', categoria: q.get('categoria') || '', pagina: 1 }; } await catalogo(); }
    else if (r === 'carrinho') await carrinho();
    else if (r === 'checkout') await checkout();
    else if (r === 'entrar') entrar();
    else if (r === 'cadastro') cadastro();
    else if (r === 'pedidos') await pedidos();
    else await home();
  } catch (e) { app.innerHTML = `<p class="err">${esc(e.message)}</p>`; }
  scrollTo(0, 0);
}
addEventListener('hashchange', route);
$('#hs').onsubmit = e => { e.preventDefault(); go('#/produtos?busca=' + encodeURIComponent($('#hq').value.trim())); };
refresh().then(route);
