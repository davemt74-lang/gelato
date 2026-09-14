<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
app_boot_session();
$csrf = app_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0b09">
<meta name="description" content="Wholesale gelato inquiry for restaurants, cafés, hotels, event venues, caterers, and specialty retailers.">
<title>Wholesale Gelato · Stonefellows</title>
<link rel="stylesheet" href="css/public.css?v=20260914-3">
<style>
  .wholesale-hero{padding:72px 24px 54px;background:linear-gradient(135deg,#171b1a,#2d3732);color:#fff}.wholesale-hero-inner{max-width:1180px;margin:auto;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);gap:40px;align-items:end}.wholesale-hero h1{max-width:850px;margin:8px 0 18px;font-size:clamp(46px,8vw,92px);line-height:.9;letter-spacing:-.065em}.wholesale-hero p{max-width:720px;margin:0;color:rgba(255,255,255,.72);font-size:18px;line-height:1.6}.wholesale-side{padding:22px;border:1px solid rgba(255,255,255,.15);border-radius:24px;background:rgba(255,255,255,.08);backdrop-filter:blur(10px)}.wholesale-side strong{display:block;font-size:18px}.wholesale-side ul{margin:14px 0 0;padding-left:20px;color:rgba(255,255,255,.72);line-height:1.8}.wholesale-wrap{max-width:1180px;margin:0 auto;padding:44px 24px 80px}.wholesale-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:24px;align-items:start}.wholesale-card{padding:28px;border:1px solid #dddcd5;border-radius:24px;background:#fff;box-shadow:0 18px 50px rgba(25,26,22,.07)}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px}.form-field{display:grid;gap:7px}.form-field.wide{grid-column:1/-1}.form-field label{font-size:12px;font-weight:850}.form-field small{color:#747770;line-height:1.45}.form-field input,.form-field select,.form-field textarea{width:100%;min-height:46px;padding:11px 12px;border:1px solid #d8d9d4;border-radius:12px;background:#fff;color:#171815;font:inherit;outline:none}.form-field textarea{min-height:110px;resize:vertical}.form-field input:focus,.form-field select:focus,.form-field textarea:focus{border-color:var(--brand-primary,#d94a2b);box-shadow:0 0 0 4px rgba(217,74,43,.10)}.check-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.check{display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid #e1e2dd;border-radius:11px;background:#fafaf8;font-size:12px}.check input{width:auto;min-height:0;margin-top:2px}.submit-row{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:22px;padding-top:20px;border-top:1px solid #e4e4df}.submit-row p{margin:0;color:#747770;font-size:11px;line-height:1.5}.wholesale-submit{min-height:46px;padding:0 22px;border:0;border-radius:12px;color:#fff;background:var(--brand-primary,#d94a2b);font-weight:850}.wholesale-submit:disabled{opacity:.55}.side-stack{display:grid;gap:14px}.info-card{padding:20px;border:1px solid #dddcd5;border-radius:18px;background:#fff}.info-card h3{margin:0 0 8px;font-size:16px}.info-card p{margin:0;color:#70736e;font-size:12px;line-height:1.6}.info-card strong{display:block;margin-top:12px;font-size:12px}.message{margin-top:16px;padding:12px 14px;border-radius:12px;font-size:12px}.message.good{background:#eef9f1;color:#176b3d;border:1px solid #b8dfc4}.message.bad{background:#fff1ef;color:#9e2c24;border:1px solid #efc4bd}.hidden{display:none}.hp{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}.eyebrow{font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase;color:var(--brand-secondary,#ff835f)}@media(max-width:850px){.wholesale-hero-inner,.wholesale-layout{grid-template-columns:1fr}.wholesale-side{max-width:520px}.form-grid{grid-template-columns:1fr}.form-field.wide{grid-column:auto}}@media(max-width:520px){.check-grid{grid-template-columns:1fr}.submit-row{align-items:stretch;flex-direction:column}.wholesale-submit{width:100%}}
</style>
<link rel="stylesheet" href="css/stonefellows-public-v2.css?v=20260914-2">
</head>
<body>
<header class="public-header"><a class="public-brand" href="index.php"><span class="public-logo" id="wholesaleLogo">SF</span><span><strong id="wholesaleBrand">Stonefellows</strong><span>Pizzeria + Bar</span></span></a><nav class="public-nav"><a href="index.php">Home</a><a href="menu.php">Menu</a><a href="gelato.php">Gelato</a><a href="about.php">About</a><a href="locations.php">Locations</a><a href="contact.php">Contact</a><a class="primary" href="login.php">Login</a></nav></header>
<section class="wholesale-hero">
  <div class="wholesale-hero-inner">
    <div><p class="eyebrow">Wholesale program</p><h1>Gelato built for your menu.</h1><p>Tell us about your restaurant, café, hotel, event program, or retail operation. We’ll use your request to build the right flavor, package, fulfillment, and private-label conversation.</p></div>
    <aside class="wholesale-side"><strong>Designed for foodservice</strong><ul><li>Core and custom flavors</li><li>Foodservice tubs and retail formats</li><li>Private-label opportunities</li><li>Recurring or seasonal orders</li><li>Pickup and delivery planning</li></ul></aside>
  </div>
</section>
<main class="wholesale-wrap">
  <div class="wholesale-layout">
    <section class="wholesale-card">
      <p class="eyebrow">Start a wholesale conversation</p><h2 style="margin:7px 0 24px;font-size:34px;letter-spacing:-.04em">Tell us what you need.</h2>
      <form id="wholesaleForm" novalidate>
        <input type="hidden" id="csrf" value="<?= app_escape($csrf) ?>">
        <div class="hp" aria-hidden="true"><label>Leave this blank<input id="website_check" tabindex="-1" autocomplete="off"></label></div>
        <div class="form-grid">
          <div class="form-field"><label for="businessName">Business name *</label><input id="businessName" maxlength="200" required autocomplete="organization"></div>
          <div class="form-field"><label for="businessType">Business type</label><select id="businessType"><option value="">Select type</option><option>Restaurant</option><option>Café / Coffee Shop</option><option>Hotel / Resort</option><option>Bar / Brewery</option><option>Catering</option><option>Event Venue</option><option>Specialty Grocery / Market</option><option>Corporate / Workplace</option><option>Other</option></select></div>
          <div class="form-field"><label for="contactName">Contact name *</label><input id="contactName" maxlength="180" required autocomplete="name"></div>
          <div class="form-field"><label for="email">Email *</label><input id="email" type="email" maxlength="254" required autocomplete="email"></div>
          <div class="form-field"><label for="phone">Phone</label><input id="phone" maxlength="50" autocomplete="tel"></div>
          <div class="form-field"><label for="website">Website</label><input id="website" type="url" maxlength="500" placeholder="https://"></div>
          <div class="form-field wide"><label for="location">Business location / delivery area</label><input id="location" maxlength="300" placeholder="City, neighborhood, or delivery address"></div>
          <div class="form-field"><label for="estimatedMonthlyVolume">Estimated monthly volume</label><select id="estimatedMonthlyVolume"><option value="">Not sure yet</option><option>1–5 foodservice tubs</option><option>6–15 foodservice tubs</option><option>16–30 foodservice tubs</option><option>31–60 foodservice tubs</option><option>60+ foodservice tubs</option><option>Retail pint program</option><option>Custom / event volume</option></select></div>
          <div class="form-field"><label for="orderFrequency">Order frequency</label><select id="orderFrequency"><option value="">Not sure yet</option><option>Weekly</option><option>Every two weeks</option><option>Monthly</option><option>Seasonal</option><option>Events / as needed</option></select></div>
          <div class="form-field wide"><label>Package formats of interest</label><div class="check-grid" id="packageChecks"><label class="check"><input type="checkbox" value="Foodservice tubs"><span>Foodservice tubs</span></label><label class="check"><input type="checkbox" value="Retail pints"><span>Retail pints</span></label><label class="check"><input type="checkbox" value="Single-serve cups"><span>Single-serve cups</span></label><label class="check"><input type="checkbox" value="Custom / private label"><span>Custom / private label</span></label></div></div>
          <div class="form-field wide"><label for="flavorsInterest">Flavors or menu concept</label><textarea id="flavorsInterest" maxlength="3000" placeholder="Core flavors, seasonal ideas, dessert pairing, custom flavor request, dietary needs, etc."></textarea></div>
          <div class="form-field"><label for="freezerCapacity">Freezer capacity</label><select id="freezerCapacity"><option value="">Unknown</option><option>Dedicated frozen storage available</option><option>Limited frozen storage</option><option>Need help sizing storage</option><option>No freezer yet</option></select></div>
          <div class="form-field"><label for="fulfillmentPreference">Fulfillment preference</label><select id="fulfillmentPreference"><option value="">Open to options</option><option>Pickup</option><option>Delivery</option><option>Either pickup or delivery</option></select></div>
          <div class="form-field"><label for="desiredStartDate">Desired start date</label><input id="desiredStartDate" type="date"></div>
          <div class="form-field"><label for="currentSupplier">Current frozen dessert supplier</label><input id="currentSupplier" maxlength="200" placeholder="Optional"></div>
          <div class="form-field wide"><label class="check"><input type="checkbox" id="privateLabelInterest"><span>I’m interested in a private-label or house flavor program.</span></label></div>
          <div class="form-field wide"><label for="notes">Anything else we should know?</label><textarea id="notes" maxlength="5000" placeholder="Timing, serving format, expected covers, menu placement, event details, or questions."></textarea></div>
          <div class="form-field wide"><label class="check"><input type="checkbox" id="consent" required><span>I agree that the restaurant may contact me about this wholesale request. *</span></label></div>
        </div>
        <div class="submit-row"><p>Submitting this form creates a private wholesale lead for the restaurant team and its internal AI agent. It does not place an order or commit either party to pricing.</p><button class="wholesale-submit" id="submitButton" type="submit">Submit wholesale request</button></div>
        <div class="message hidden" id="formMessage" role="status"></div>
      </form>
    </section>
    <aside class="side-stack">
      <article class="info-card"><h3>What happens next?</h3><p>Your submission enters our wholesale pipeline. The team can qualify volume, schedule samples, build pricing, and track follow-up from one record.</p></article>
      <article class="info-card"><h3>Restaurant AI assisted</h3><p>Our private operations agent can summarize wholesale demand, identify follow-ups, compare flavor requests, and connect opportunities with production knowledge.</p></article>
      <article class="info-card"><h3>Custom programs</h3><p>Use the form even if you do not know your exact volume yet. We can start with menu use, serving size, freezer capacity, and expected guest traffic.</p><strong id="contactLine"></strong></article>
    </aside>
  </div>
</main>
<footer class="sf-public-footer"><div class="sf-footer-grid"><div class="sf-brand"><strong>Stonefellows</strong><span>Pizzeria + Bar</span></div><div><strong>Links</strong><nav><a href="menu.php">Menu</a><a href="gelato.php">Gelato</a><a href="about.php">About</a><a href="locations.php">Locations</a><a href="contact.php">Contact</a><a href="jobs.html">Jobs</a><a href="catering.php">Catering</a><a href="wholesale.php">Wholesale</a></nav></div><div><strong>Wholesale</strong><nav><a href="wholesale.php">Wholesale Inquiry</a><a href="wholesale-login.php">Buyer Login</a></nav></div><div><strong>Account</strong><nav><a href="login.php">Login</a><a href="forgot-password.php">Forgot Password</a></nav></div><div><strong>Visit</strong><nav><a href="locations.php">Locations</a><a href="contact.php">Hours + Contact</a></nav></div></div></footer>
<script>
(async()=>{
  const message=document.getElementById('formMessage'),button=document.getElementById('submitButton');
  const show=(text,bad=false)=>{message.textContent=text;message.className='message '+(bad?'bad':'good')};
  try{
    const response=await fetch('api/public-brand.php',{headers:{Accept:'application/json'},cache:'no-store'});const data=await response.json();
    if(data.brand){const b=data.brand;document.getElementById('wholesaleBrand').textContent=b.restaurantName||'Stonefellows';const logo=document.getElementById('wholesaleLogo');if(b.logoUrl)logo.innerHTML=`<img src="${String(b.logoUrl).replaceAll('"','%22')}" alt="">`;else logo.textContent=b.logoText||'SF';document.title=`Wholesale Gelato · ${b.restaurantName||'Stonefellows'}`;document.getElementById('contactLine').textContent=[b.email,b.phone].filter(Boolean).join(' · ')}
  }catch{}
  document.getElementById('wholesaleForm').addEventListener('submit',async event=>{
    event.preventDefault();message.className='message hidden';
    if(!event.currentTarget.reportValidity())return;
    button.disabled=true;button.textContent='Submitting…';
    const payload={
      csrf_token:document.getElementById('csrf').value,website_check:document.getElementById('website_check').value,
      businessName:document.getElementById('businessName').value.trim(),businessType:document.getElementById('businessType').value,
      contactName:document.getElementById('contactName').value.trim(),email:document.getElementById('email').value.trim(),phone:document.getElementById('phone').value.trim(),website:document.getElementById('website').value.trim(),location:document.getElementById('location').value.trim(),
      estimatedMonthlyVolume:document.getElementById('estimatedMonthlyVolume').value,orderFrequency:document.getElementById('orderFrequency').value,
      packageSizes:[...document.querySelectorAll('#packageChecks input:checked')].map(input=>input.value),flavorsInterest:document.getElementById('flavorsInterest').value.trim(),privateLabelInterest:document.getElementById('privateLabelInterest').checked,
      freezerCapacity:document.getElementById('freezerCapacity').value,fulfillmentPreference:document.getElementById('fulfillmentPreference').value,desiredStartDate:document.getElementById('desiredStartDate').value,currentSupplier:document.getElementById('currentSupplier').value.trim(),notes:document.getElementById('notes').value.trim(),consent:document.getElementById('consent').checked
    };
    try{const response=await fetch('api/public-wholesale.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':payload.csrf_token},body:JSON.stringify(payload)});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Could not submit the wholesale request.');show(data.message||'Wholesale request received.');event.currentTarget.reset();window.scrollTo({top:document.querySelector('.wholesale-wrap').offsetTop-20,behavior:'smooth'});}catch(error){show(error.message||'Could not submit the wholesale request.',true)}finally{button.disabled=false;button.textContent='Submit wholesale request'}
  });
})();
</script>
</body>
</html>