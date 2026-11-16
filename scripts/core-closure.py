#!/usr/bin/env python3
"""Ana API alan cekirdeginin bagimlilik kapanisi: panelin sardigi siniflardan baslar, `use App\\...` ve ayni
ad alanindaki referanslari izler. Cikti: kopyalanmasi gereken dosya listesi (JSON).

Kullanim: python3 scripts/core-closure.py ../otosanayide-api [ek/baslangic/dosya.php ...]
Yeni dosya cikarsa: kopyalayin, core-manifest.json'a ekleyin, `php artisan panel:core-check --write-manifest`.
"""
import re,os,sys,json
os.chdir(sys.argv[1])
def path(cls): return cls.replace('App\\','app/').replace('\\','/')+'.php'
seeds=[f'app/Domain/Admin/{f}' for f in os.listdir('app/Domain/Admin')]+['app/Domain/Content/Activations.php','app/Domain/Content/BusinessReports.php','app/Domain/User/Privacy.php','app/Domain/User/PrivacyExports.php','app/Domain/Payments/PaymentCases.php','app/Domain/Ads/AdTariffs.php','app/Domain/Brand/BrandAdmin.php','app/Domain/Ads/Campaigns.php','app/Domain/Brand/BrandReview.php','app/Domain/Brand/BrandRefundReview.php','app/Domain/Brand/BrandCancellation.php','app/Domain/Brand/BrandAcceptance.php','app/Domain/Brand/BrandInventory.php','app/Domain/Brand/BrandOrders.php','app/Domain/Brand/BrandMedia.php','app/Domain/Payments/PaymentGateway.php','app/Domain/Auth/Totp.php','app/Domain/Account/AccountFence.php','app/Support/Query.php','app/Support/Body.php']
seeds+=['app/Domain/Brand/BrandServing.php']+sys.argv[2:]
seen=set();stack=list(seeds)
while stack:
    f=stack.pop()
    if f in seen or not os.path.exists(f): continue
    seen.add(f)
    src=open(f).read()
    for m in re.findall(r'^use (App\\[\w\\]+)',src,re.M): stack.append(path(m))
    for m in re.findall(r'\\(App\\[\w\\]+)',src): stack.append(path(m))
    d=os.path.dirname(f)
    for cand in os.listdir(d):
        c=cand[:-4]
        if cand.endswith('.php') and re.search(r'\b'+c+r'::|new '+c+r'\b|\b'+c+r' \$|\b'+c+r'\)|\(\?'+c+r' ',src): stack.append(os.path.join(d,cand))
print(json.dumps(sorted(seen)))
