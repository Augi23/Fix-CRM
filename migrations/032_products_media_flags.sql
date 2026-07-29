-- Per-produkt volba sekcí Galerie: která média se použijí na e-shopu (a v katalogu).
-- show_studio = studiovka jako hlavní obrázek + Meta/Google katalog; show_gallery = klasické
-- fotky jako galerie; show_360 = 360° prohlídka. Výchozí vše zapnuto (dnešní chování).
-- Runtime guard ensureProductsCrmColumns() zrcadlí totéž.
ALTER TABLE products ADD COLUMN IF NOT EXISTS show_studio TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN IF NOT EXISTS show_gallery TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN IF NOT EXISTS show_360 TINYINT(1) NOT NULL DEFAULT 1;
