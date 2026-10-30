# Parcelwise: Claude için notlar

WooCommerce mağazalarında "Siparişim nerede?" sorusunu sohbetle cevaplayan, müşteriye başkasının siparişini asla göstermeyen bir WordPress eklentisi. Furkan'ın SE / IE / FDE rolleri için portföy projesi; hedef, gerçek kullanıcısı olan ve mülakatta anlatılabilir bir ürün.

## Çalışma kuralları

- **Geniş yetki.** Ürün mimarisi, özellikler, algoritmalar, veri yapıları, araç ve kütüphane seçimi gibi teknik kararları Claude kendisi verir ve uygular. Onay beklemez, gerekçesini kısaca söyler.
- **Genel geçer çözümden kaçın.** İlk akla gelen ya da "herkes böyle yapıyor" denen çözümü (hazır chatbot kalıbı, şablon mimari, varsayılan ayar) seçmeden önce bu ürünün kendi problemine daha iyi uyan bir çözüm ara. Ama farklı olmak için farklı olma: seçimi gerçek bir sürtünmeye, teste ya da ölçüme dayandır ve neden standart olandan iyi olduğunu söyle.
- **Yetkinin dışında kalanlar:** Dışarıya dönük ya da geri alınması zor işler için yine Furkan'a sor. Bunlar: GitHub'a push, eklenti dizinine gönderim, birine mesaj veya e-posta, yaklaşık 1 doları geçecek ücretli API çalıştırmaları. Ölçüm betiğindeki `--approve-harness` onayını da Furkan verir.
- **Birkaç adımda bir geri bak.** Ne yapıldı, ne doğrulandı, ne sadece varsayıldı, hâlâ hedefe hizmet ediyor mu? Bunu kısaca söyle.
- **Açıklamalar Türkçe ve sade, kod ve yorumlar İngilizce.**
- **Claude'a atıf yok.** Commit, pull request ve README'de Claude ortak yazar ya da katkıda bulunan olarak görünmez (`Co-Authored-By` satırı veya "Generated with Claude Code" notu yok). Genel kural `~/.claude/CLAUDE.md` içinde de var.

## Proje haritası

- `plugin/`: Dağıtılan eklenti. Siparişi bulma ve sahiplik kontrolü `includes/class-parcelwise-order-lookup.php` dosyasında, Claude ile sohbet döngüsü `includes/class-parcelwise-chat.php` dosyasında, REST uçları `includes/class-parcelwise-rest-controller.php` dosyasında.
- `dev/`: Test düzeneği. WordPress Playground mağazaları, test siparişleri, sahte Claude (`dev/osa-dev/osa-dev.php`). `npm test` ile 30 senaryo HPOS ve klasik depolamada çalışır.
- `eval/`: Gerçek Claude ile ölçüm. `eval/run-eval.mjs`, vakalar `eval/cases.json`, notlama `eval/grading.mjs` (kod kontrolleri ve Sonnet 5.5 notlayıcı). Sonuçlar `.claude/hillclimb/` altında; 1. tur `order-chat-r1/`.
- `dist/`: Dizine gönderilecek zip.

## Bilinen tuzaklar

- Playground'u Git Bash'ten başlatma: `--mount=./a:/b` yolu Windows yoluna çevriliyor. PowerShell ya da Node `spawn` kullan.
- Plugin Check'in PHPCS kontrolleri Playground'da dosya kilidi yüzünden çalışmaz; `dev/blueprint-check.json` bunu yamalıyor. Runtime kontrolleri yine de çalışmıyor.
- Zip'i Windows'un kendi `tar` aracıyla (`$env:WINDIR\System32\tar.exe -a`) ve `dist/` içinde yap. Git Bash'teki `tar` zip üretmez, sessizce düz tar yazar. Windows `tar` da uzun yollarda klasöre geçemiyor.
- Gönderilecek zip `dist/parcelwise-<sürüm>.zip`; içinde tek bir `parcelwise/` klasörü olmalı.
