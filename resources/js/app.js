import '../css/design.css';
import '../css/member.css';
import '../css/copp.css';
import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
if (!document.documentElement.hasAttribute('data-livewire-enabled')) {
    window.Alpine = Alpine;
    Alpine.start();
}

const money=value=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
const compact=value=>new Intl.NumberFormat('id-ID',{notation:'compact',maximumFractionDigits:1}).format(value);
const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
Chart.defaults.font.family='Inter, Segoe UI, sans-serif';
Chart.defaults.font.size=11;
Chart.defaults.color='#94a3b8';
Chart.defaults.plugins.legend.labels.usePointStyle=true;
Chart.defaults.plugins.legend.labels.boxWidth=7;
const palette=['#10b981','#94a3b8','#f59e0b','#ef4444','#6366f1'];
function draw(name,labels,series,type='line',monetary=true){
    const canvas=document.querySelector(`[data-chart="${name}"]`);
    if(!canvas)return;
    if(!labels.length)canvas.closest('.chart-container')?.insertAdjacentHTML('beforeend','<p class="chart-empty">Belum ada periode untuk ditampilkan.</p>');
    new Chart(canvas,{type,data:{labels,datasets:series.map((s,i)=>({label:s.label,data:s.data,borderColor:s.color||palette[i],backgroundColor:type==='bar'?(s.color||palette[i]):`${s.color||palette[i]}12`,borderWidth:2,borderRadius:4,maxBarThickness:14,tension:.3,pointRadius:0,pointHoverRadius:4,fill:type==='line'&&i===0}))},options:{responsive:true,maintainAspectRatio:false,animation:reduced?false:{duration:450},interaction:{intersect:false,mode:'index'},plugins:{legend:{display:name!=='cashflow',position:'bottom',labels:{padding:18}},tooltip:{backgroundColor:'#0b1220',padding:12,callbacks:{label:c=>`${c.dataset.label}: ${monetary?money(c.parsed.y):compact(c.parsed.y)}`}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{maxTicksLimit:7,maxRotation:0}},y:{beginAtZero:true,grid:{color:'#f1f5f9'},border:{display:false},ticks:{maxTicksLimit:5,callback:v=>monetary?`Rp${compact(v)}`:compact(v)}}}}});
    canvas.dataset.ready='true';
}
const dashboard=document.getElementById('dashboard-data');
if(dashboard){
    const {chart:c,coverage}=JSON.parse(dashboard.textContent);
    draw('cashflow',c.labels,[{label:'Arus masuk',data:c.inflow},{label:'Arus keluar',data:c.outflow}],'bar');
    draw('obligations',c.labels,[{label:'ROI jatuh tempo',data:c.due,color:'#94a3b8'},{label:'ROI dibayar',data:c.paid,color:'#10b981'}]);
    draw('liquidity',c.labels,[{label:'Kas / saldo tersedia',data:c.cash},{label:'Tunggakan',data:c.outstanding,color:'#f59e0b'},{label:'Funding gap',data:c.gap,color:'#ef4444'}]);
    draw('members',c.labels,[{label:'Peserta kohor',data:c.members}],'line',false);
    draw('dependency',c.labels,[{label:'Setoran baru',data:c.deposits},{label:'Pembayaran ROI',data:c.paid,color:'#64748b'}],'bar');
    const ratio=Math.min(100,Math.max(0,coverage));
    const coverageCanvas=document.querySelector('[data-chart="coverage"]');
    if(coverageCanvas) new Chart(coverageCanvas,{type:'doughnut',data:{labels:['Terbayar','Belum terbayar'],datasets:[{data:[ratio,100-ratio],backgroundColor:['#10b981','#edf2f7'],borderWidth:0,borderRadius:6,spacing:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'82%',rotation:-90,animation:reduced?false:{duration:500},plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`${c.label}: ${c.raw.toFixed(1)}%`}}}}});
}
const portfolio=document.getElementById('portfolio-data');
const memberDashboard=document.getElementById('member-dashboard-data');
if(memberDashboard){const c=JSON.parse(memberDashboard.textContent);draw('member-balance',c.labels,[{label:'Saldo tersedia',data:c.cash}]);}
if(portfolio){const rows=JSON.parse(portfolio.textContent);draw('portfolio',rows.map(r=>r.due_date.slice(0,10)),[{label:'ROI dijanjikan jatuh tempo',data:rows.map(r=>r.amount),color:'#94a3b8'},{label:'ROI dibayar',data:rows.map(r=>r.paid_amount),color:'#10b981'}]);}
const scenario=document.getElementById('scenario-data');
if(scenario){const rows=JSON.parse(scenario.textContent);draw('scenario',rows.map(r=>`Hari ${r.day}`),[{label:'Kas bebas',data:rows.map(r=>r.cash)},{label:'Kewajiban tertunggak',data:rows.map(r=>r.outstanding),color:'#f59e0b'},{label:'Funding gap',data:rows.map(r=>r.funding_gap),color:'#ef4444'}]);}
const dialog=document.getElementById('confirm-dialog');let pending=null;
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(form.dataset.confirmed==='yes')return;e.preventDefault();pending=form;document.getElementById('confirm-message').textContent=form.dataset.confirm;dialog.showModal();}));
document.querySelector('[data-confirm-cancel]')?.addEventListener('click',()=>{dialog.close();pending=null;});
document.querySelector('[data-confirm-accept]')?.addEventListener('click',()=>{dialog.close();if(pending){pending.dataset.confirmed='yes';pending.requestSubmit();}});
document.querySelectorAll('form[method="post"]').forEach(form=>form.addEventListener('submit',e=>{if(e.defaultPrevented)return;form.setAttribute('aria-busy','true');const b=e.submitter||form.querySelector('button[type="submit"],button:not([type])');if(b){b.classList.add('is-loading');setTimeout(()=>{b.disabled=true;},0);}}));
document.querySelectorAll('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{const input=document.getElementById(b.dataset.copy);input.select();try{await navigator.clipboard.writeText(input.value);b.textContent='Link disalin';}catch{b.textContent='Tekan Ctrl+C untuk menyalin';}}));
document.querySelectorAll('[data-amount]').forEach(button=>button.addEventListener('click',()=>{
    const input=button.closest('form').querySelector('[name="amount"]');
    input.value=button.dataset.amount; input.dispatchEvent(new Event('input',{bubbles:true}));
    button.closest('form').querySelectorAll('[data-amount]').forEach(option=>option.setAttribute('aria-pressed',String(option===button)));
}));
const packagePicker=document.querySelector('.member-app [name="roi_package_id"]');
const syncPackage=()=>{
    if(!packagePicker) return;
    document.querySelectorAll('[data-package-terms]').forEach(terms=>{terms.hidden=terms.dataset.packageTerms!==packagePicker.value;});
    const option=packagePicker.selectedOptions[0], amount=packagePicker.form?.querySelector('[name="amount"]');
    if(amount && option?.dataset.price){
        amount.min=option.dataset.price;
        amount.readOnly=option.dataset.fixed==='1';
        if(amount.readOnly) amount.value=option.dataset.price;
    }
};
packagePicker?.addEventListener('change',syncPackage);
syncPackage();
document.querySelectorAll('.member-app .table-wrap table').forEach(table=>{
    const labels=[...table.querySelectorAll('thead th')].map(header=>header.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row=>[...row.children].forEach((cell,index)=>{if(cell.colSpan===1)cell.dataset.label=labels[index]||'';}));
});
