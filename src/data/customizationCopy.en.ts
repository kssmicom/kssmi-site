/**
 * English editorial & technical guide copy for Kssmi Customization Catalog (/customization/).
 * Keeps top intro compact above the component grid and places deeper engineering comparisons & FAQs below pagination.
 */
export interface CustomizationGroupCopy {
  seoTitle: string;
  seoDescription: string;
  heading: string;
  intro: string;
  quickPills: string[];
  guideTitle: string;
  guideSubtitle: string;
  tableHeaders: string[];
  tableRows: string[][];
  engineeringTips: string[];
  faqTitle: string;
  faqs: Array<{ question: string; answer: string }>;
}

export const CUSTOMIZATION_COPY_EN: Record<string, CustomizationGroupCopy> = {
  all: {
    seoTitle: 'Custom Eyewear Components & Material Swatches | Kssmi OEM/ODM',
    seoDescription: 'Browse 367 custom eyewear components at Kssmi: UV400 lenses, IP/PVD plating finishes, temple core wires, precision hinges, nose pads, and carbon/glass fiber weaves.',
    heading: 'Eyewear Customization Components',
    intro: 'Select specific lenses, plating finishes, hinges, temple core wires, nose pads, and composite weaves for your OEM/ODM eyewear collection. Click any component image to inspect fine details, or click "+ Select" to build a specification list for your quotation.',
    quickPills: [
      'MOQ: 300 Pairs / Model (200 Carbon Fiber)',
      'Drawing Time: 3–10 Working Days',
      'Custom Sample: 50–60 Days',
      '100% Pre-Shipment Inspection',
    ],
    guideTitle: 'How to Specify Custom Components with Kssmi',
    guideSubtitle: 'Every catalog frame or custom design can be configured across six core component families. All component codes use Kssmi identifiers (KS-*) for unambiguous sampling and bulk production.',
    tableHeaders: ['Component Group', 'Primary Role', 'Compatible Frame Types', 'MOQ & Sampling Note'],
    tableRows: [
      ['Lens Options (KS-LN)', 'Optical clarity, UV400 protection & tint aesthetics', 'Acetate, Titanium, Metal, Carbon Fiber, Rimless', 'TAC, CR-39, Nylon, PC & optical demo lenses (no Rx)'],
      ['Plating Finishes (KS-PL)', 'Metallic color tone, sweat & scratch durability', 'Titanium, Stainless Steel, Monel & hardware parts', 'IP/PVD vacuum plating in polished or semi-matte'],
      ['Temple Core Wires (KS-CW)', 'Structural reinforcement & visible temple detailing', 'Cellulose & bio-acetate temples (ideal on crystal)', 'Polished or semi-matte; custom engraved logos available'],
      ['Hinge Hardware (KS-HG)', 'Opening torque, fold precision & long-term durability', 'Acetate, Metal, Titanium & Rimless frames', 'Matched to front thickness, barrel count & temple angle'],
      ['Nose Pads (KS-NP)', 'Bridge comfort, anti-slip grip & weight distribution', 'Adjustable metal pad arms & hybrid frames', 'Titanium, Al-Mg, phosphor bronze & ergonomic pads'],
      ['Carbon & Glass Fiber (KS-CF)', 'Ultra-light stiffness & woven composite styling', 'Performance-luxury sun & optical frames', '200 pairs/model MOQ for carbon-fiber styles'],
    ],
    engineeringTips: [
      'Mix-and-match codes freely during inquiry: select your preferred lens tint (KS-LN-*), plating tone (KS-PL-*), and hardware codes, then submit them together on our Quote page.',
      'For rimless or half-rim frames, pair your chosen lens tint with Nylon (PA) or Polycarbonate (PC) substrates to prevent drill-hole cracking.',
      'When using crystal or translucent acetate temples, coordinate your Temple Core Wire (KS-CW-*) and Hinge (KS-HG-*) plating finish so visible internal metal matches seamlessly.',
      'Kssmi focuses exclusively on eyewear manufacturing—we do not produce or resell retail packaging, but we pack finished frames into customer-supplied cases at no extra charge.',
    ],
    faqTitle: 'Component Selection FAQ',
    faqs: [
      {
        question: 'Can I combine components from multiple categories in one custom frame?',
        answer: 'Yes. Select the Kssmi codes (such as a lens tint, IP plating color, hinge model, core wire, and nose pad) using the "+ Select" button on any card, then send the combined specification list with your frame reference or sketch on our Quote page.',
      },
      {
        question: 'Do custom components increase the minimum order quantity?',
        answer: 'Standard stock components fit Kssmi’s regular MOQ of 300 pairs per model (up to 3 colors at 100 pairs/color) or 200 pairs per model for carbon fiber. Special custom-cast colors, rare lens tints, or proprietary engraved core-wire rollers may carry separate material minimums or tooling fees, which we itemize before work begins.',
      },
      {
        question: 'Are component samples available before bulk production?',
        answer: 'Yes. Once your engineering drawings and component codes are confirmed, we craft functional custom samples (50–60 days including tooling where required) so you can evaluate real-world color, weight, hinge tension, and optical clarity prior to bulk production.',
      },
    ],
  },

  lenses: {
    seoTitle: 'Custom Eyewear Lens Options & Tint Swatches (UV400) | Kssmi',
    seoDescription: 'Choose from 122 custom eyewear lens tints at Kssmi: grey, brown, green, blue, fashion tints, blue-light blocking, and mirror coatings in TAC, CR-39, Nylon, and PC.',
    heading: 'Lens Options & Tint Swatches',
    intro: 'Browse 122 sun, fashion, mirror-coated, and blue-light-filtering lens options. Available in TAC polarized, CR-39, Nylon (PA), Polycarbonate (PC), and non-prescription optical demo lenses with UV400 protection.',
    quickPills: [
      'Substrates: TAC / CR-39 / Nylon / PC',
      'UV400 Standard Protection',
      'CAT.0 – CAT.4 Filter Categories',
      'Plano & Demo Only (No Rx Lenses)',
    ],
    guideTitle: 'Lens Substrate & Filter Category Guide',
    guideSubtitle: 'Match your chosen swatch code (KS-LN-*) with the right optical substrate and coating stack for your frame construction and destination market.',
    tableHeaders: ['Lens Substrate', 'Optical Clarity & Weight', 'Frame Compatibility', 'Recommended Use'],
    tableRows: [
      ['Nylon (PA)', 'High clarity (Abbe ~52), ultra-light', 'All frames—ideal for rimless & half-rim drill mounts', 'Luxury sunwear, titanium & carbon-fiber collections'],
      ['CR-39', 'Benchmark optical clarity (Abbe ~58)', 'Full-rim acetate & metal frames (avoid rimless drilling)', 'Boutique & heritage acetate sunglasses'],
      ['TAC Polarized', 'Lightweight multi-layer polarized film', 'Full-rim sun frames only', 'Commercial polarized sunwear & driving lines'],
      ['Polycarbonate (PC)', 'Shatter-resistant, very lightweight', 'High-wrap sport, rimless & active frames', 'Outdoor sport, safety-oriented & drop-ball tested SKUs'],
      ['Optical Demo / Blue-Block', 'Crystal clear or subtle warm anti-blue', 'Optical frames & non-Rx screen glasses', 'Optician display glazing or retail blue-light eyewear'],
    ],
    engineeringTips: [
      'CAT.3 (8–18% VLT) is the global standard for general-purpose sunwear and daytime driving; CAT.1–CAT.2 suits fashion tints and gradient transitions.',
      'For flat 2-base fashion lenses or dark mirror lenses, add an inner anti-reflective (backside AR) coating to eliminate bounce-back eye-side glare.',
      'We coordinate independent third-party testing (EN ISO 12312-1, ANSI Z80.3, AS/NZS 1067.1, FDA 21 CFR 801.410) for finished SKUs at actual lab cost.',
    ],
    faqTitle: 'Lens Options FAQ',
    faqs: [
      {
        question: 'Does Kssmi manufacture prescription (Rx) lenses?',
        answer: 'No. Kssmi supplies finished plano sun lenses (TAC, CR-39, Nylon, PC) and fits optical frames with factory demo lenses or non-prescription blue-light-filtering lenses ready for local optician glazing.',
      },
      {
        question: 'Which lens material works best for rimless sunglasses?',
        answer: 'Always select Nylon (PA) or Polycarbonate (PC) for rimless drill-mount and half-rim tension-cord designs; standard CR-39 and TAC films are vulnerable to stress cracking around screw holes.',
      },
      {
        question: 'Can any swatch color be ordered with polarized or mirror coatings?',
        answer: 'Most grey, brown, and green CAT.3 tints support polarized stacks and vacuum mirror coatings. Share your chosen KS-LN code and desired substrate so our engineers confirm availability and any custom-tint MOQ.',
      },
    ],
  },

  plating: {
    seoTitle: 'Custom Eyewear Plating Colors — Polished & Semi-Matte IP/PVD | Kssmi',
    seoDescription: 'Explore 18 IP/PVD vacuum plating colors for custom titanium and metal eyewear: polished and semi-matte gold, rose gold, Japanese gold, antique copper, silver, gunmetal, and black.',
    heading: 'Plating Finishes (IP / PVD)',
    intro: 'Select from 18 vacuum-deposited IP/PVD metallic tones across mirror-polished and satin semi-matte finishes. Engineered for titanium, stainless steel, Monel frames, and matching metal hinges or nose pads.',
    quickPills: [
      'Process: IP / PVD Vacuum Plating',
      'Finishes: Polished & Semi-Matte',
      'Sweat & Corrosion Resistant',
      'Hypoallergenic Titanium Compatible',
    ],
    guideTitle: 'Polished vs. Semi-Matte Plating Selection',
    guideSubtitle: 'Vacuum Ion Plating (IP/PVD) delivers superior abrasion resistance and batch-to-batch color consistency compared with conventional wet rack plating.',
    tableHeaders: ['Finish Family', 'Surface Appearance', 'Practical Advantage', 'Best Frame Pairing'],
    tableRows: [
      ['Polished IP Plating', 'High-luster mirror metallic reflection', 'Classic luxury brilliance; easy to wipe clean', 'Dress optical frames, fine wire aviators, visible acetate inlays'],
      ['Semi-Matte IP Plating', 'Refined satin low-glare metallic sheen', 'Masks fingerprints & micro-scratches; modern architectural feel', 'Minimalist titanium optical, masculine metal & carbon-hybrid frames'],
    ],
    engineeringTips: [
      'Combining a polished bridge or rim bevel with semi-matte temples (two-tone masking) creates high-end visual depth while keeping the same frame mold.',
      'For humid coastal markets or active wearers, specify IP/PVD vacuum plating over wet plating to dramatically cut sweat-induced tarnish returns.',
      'Confirm whether hinges, rivets, and titanium nose pads should match the primary frame plating code (KS-PL-*) during engineering drawing sign-off.',
    ],
    faqTitle: 'Plating Finishes FAQ',
    faqs: [
      {
        question: 'Why does Kssmi recommend IP/PVD plating for titanium and metal frames?',
        answer: 'Ion Plating bonds metallic compounds in a vacuum chamber, yielding a denser, harder surface that resists perspiration, skin acidity, and daily abrasion far better than traditional wet electroplating.',
      },
      {
        question: 'Can we match a custom Pantone solid color instead of metallic plating?',
        answer: 'Yes. For non-metallic opaque colors or rim accents on stainless steel and titanium, Kssmi also supports Pantone-matched spray painting and cured epoxy enamel inlays.',
      },
      {
        question: 'Can two plating finishes be combined on one frame?',
        answer: 'Yes. Dual-tone plating (for example, semi-matte gunmetal front with polished gold bridge or temples) is widely supported and itemized clearly in your sample quotation.',
      },
    ],
  },

  'core-wires': {
    seoTitle: 'Custom Eyewear Temple Core Wires (Polished & Semi-Matte) | Kssmi',
    seoDescription: 'Browse 31 precision copper-alloy temple core wires for custom acetate eyewear: fish-head, sandblasted, and decorative patterns in polished and semi-matte finishes.',
    heading: 'Temple Core Wires',
    intro: 'Internal metal core wires reinforce cellulose and bio-acetate temples so opticians can heat-adjust fit without warping—and serve as a signature design centerpiece inside crystal or translucent temples.',
    quickPills: [
      '31 Structural & Decorative Profiles',
      'Finishes: Polished & Semi-Matte',
      'Dimensions Listed in mm',
      'Custom Engraved Core Tooling Available',
    ],
    guideTitle: 'Core Wire Engineering & Aesthetics',
    guideSubtitle: 'Dimensions follow Width × Front/Pattern Zone × Total Length (mm). Match the core wire profile to temple thickness and acetate transparency.',
    tableHeaders: ['Core Finish / Style', 'Visual Character', 'Best Acetate Pairing', 'Customization Option'],
    tableRows: [
      ['Polished Core Wires', 'Bright metallic contrast & crisp pattern reflection', 'Crystal clear, champagne, light tortoise & laminated temples', 'Matches polished gold, silver, rose-gold or gunmetal hinges'],
      ['Semi-Matte / Sandblasted', 'Subtle satin texture with understated depth', 'Smoked translucent, matte-finished & minimalist optical temples', 'Pairs cleanly with semi-matte IP plating hardware'],
    ],
    engineeringTips: [
      'Always align core-wire width and thickness with your acetate temple wall clearance (minimum wall thickness prevents cracking during shooting/embedding).',
      'For crystal or pale translucent acetate temples, choose a decorative fish-head or fine engraved pattern so the internal core elevates perceived retail value.',
      'Bespoke brand-engraved core wires (repeating monogram or signature texture) are available via custom stamping rollers for proprietary OEM/ODM collections.',
    ],
    faqTitle: 'Temple Core Wires FAQ',
    faqs: [
      {
        question: 'What do the millimeter numbers on each core wire card mean?',
        answer: 'They indicate the core wire’s key dimensional profile (such as front/head width, decorative section or pin zone, and overall temple length in millimeters) so our engineers match it to your temple geometry.',
      },
      {
        question: 'Can Kssmi put our brand logo or custom pattern onto the temple core wire?',
        answer: 'Yes. In addition to our 31 ready profiles, we can develop a custom-embossed or engraved temple core wire carrying your brand motif or wordmark with dedicated stamping tooling.',
      },
      {
        question: 'Do opaque black acetate frames still need a metal core wire?',
        answer: 'Yes. Even when hidden inside opaque acetate, a properly sized metal core wire is essential for structural rigidity, spring retention, and optician temple-tip adjustment.',
      },
    ],
  },

  hinges: {
    seoTitle: 'Custom Eyewear Hinge Hardware (BC, BD, BS, BT Series) | Kssmi',
    seoDescription: 'Select from 65 precision eyewear hinge components across BC, BD, BS, and BT series: reinforced, visible 3/5/7-barrel, 90°/180° stop, and positioning hinges.',
    heading: 'Hinge Hardware',
    intro: 'Compare 65 precision-milled hinge components across four engineering series (BC, BD, BS, BT)—including reinforced barrel hinges, visible riveted designs, 90°/180° stops, and French front joints.',
    quickPills: [
      '65 Precision Hardware Profiles',
      '4 Series: BC / BD / BS / BT',
      '3-, 5- & 7-Barrel Configurations',
      '100% Torque & Alignment QC',
    ],
    guideTitle: 'Choosing the Right Hinge Architecture',
    guideSubtitle: 'Hinge selection directly impacts temple stability, fold alignment, and after-sales durability. Specs note barrel height, thickness, barrel count (e.g., 3+2 = 5-barrel), and opening angle.',
    tableHeaders: ['Hinge Feature', 'Mechanical Behavior', 'Best Frame Application', 'Engineering Checkpoint'],
    tableRows: [
      ['3-Barrel (2+1) & Slim Joints', 'Compact footprint, low visual bulk', 'Slim optical acetate & lightweight wire frames', 'Verify screw retention & temple-tip balance'],
      ['5-Barrel (3+2) & Reinforced', 'High torsional stability, crisp fold tension', 'Standard to thick acetate sunglasses & luxury optical', 'Match barrel width to front end-piece thickness'],
      ['7-Barrel (4+3) Heavy-Duty', 'Maximum lateral strength & heirloom feel', 'Thick 6–8 mm sculptural acetate fronts', 'Check temple folding clearance and rivet alignment'],
      ['Visible / Pin-Riveted & 90°/180°', 'Exposes mechanical craft or locks stop angle', 'Heritage panto, vintage reissues & custom ODM designs', 'Coordinate plating finish with front pins & core wire'],
    ],
    engineeringTips: [
      'Heavier 6–8 mm acetate sunglasses should step up to 5-barrel (3+2) or 7-barrel (4+3) reinforced hinges to eliminate temple wobble over time.',
      'Not sure which series fits your sketch? Pick the visual style you prefer (e.g., KS-HG-12) and our engineers will confirm exact dimensional clearance in your 2D drawing.',
      'Every finished frame at Kssmi undergoes 100% pre-shipment inspection covering hinge symmetry, screw torque, and smooth folding resistance.',
    ],
    faqTitle: 'Hinge Hardware FAQ',
    faqs: [
      {
        question: 'What do notations like "3.5 (2+1)" or "5.0 (3+2)" mean?',
        answer: 'The first number indicates the hinge barrel height in millimeters, and the parenthesized expression shows the interlocking barrel count (2+1 = 3-barrel hinge; 3+2 = 5-barrel hinge; 4+3 = 7-barrel hinge).',
      },
      {
        question: 'Can we match the hinge plating color to our frame plating?',
        answer: 'Yes. Hinges can be plated to coordinate with your selected KS-PL-* finish (gold, silver, gunmetal, rose gold, or black) so hardware blends cleanly with core wires and decorative rivets.',
      },
      {
        question: 'Do you also support spring hinges or custom logo-stamped hinges?',
        answer: 'Yes. Tell us in your quote brief if you require spring-action temples or a discreet brand monogram stamped on the hinge plate, and we will confirm compatibility and tooling cost.',
      },
    ],
  },

  'nose-pads': {
    seoTitle: 'Custom Eyewear Nose Pads — Titanium, Metal & Ergonomic Series | Kssmi',
    seoDescription: 'Browse 40 custom eyewear nose pad options across EP comfort series and ET metal series (pure titanium, aluminum-magnesium, and phosphor bronze) with millimeter dimensions.',
    heading: 'Nose Pads',
    intro: 'Explore 40 nose pad profiles across our EP Series and ET Metal Series (pure titanium, aluminum-magnesium, and phosphor bronze). Engineered for balanced weight distribution, corrosion resistance, and all-day bridge comfort.',
    quickPills: [
      '40 Profiles: EP & ET Metal Series',
      'Titanium, Al-Mg & Phosphor Bronze',
      'Sizes from 5×10 mm to 8×16.5 mm',
      'Laser Micro-Logo Supported on Metal Pads',
    ],
    guideTitle: 'Nose Pad Material & Fit Selection',
    guideSubtitle: 'Numbers such as "7-12.5" indicate pad width × length in millimeters. Larger footprints disperse weight on heavy sun fronts; compact titanium pads keep minimalist optical frames sleek.',
    tableHeaders: ['Nose Pad Type', 'Key Benefits', 'Best Frame Pairing', 'Branding Option'],
    tableRows: [
      ['Titanium Nose Pads (ET)', 'Ultra-light, nickel-free, never yellows, lifelong durability', 'Luxury titanium & stainless steel optical and sunglasses', 'Clean micro-laser brand monogram on pad face'],
      ['Aluminum-Magnesium / Bronze (ET)', 'Rigid metallic contour with distinctive plated sheen', 'Vintage metal reissues & heritage aviator collections', 'Matches frame IP plating (gold, silver, antique copper)'],
      ['Ergonomic Comfort Pads (EP)', 'Cushioned slip-resistant contact across varied bridge shapes', 'Daily-wear commercial optical & active lightweight metal frames', 'Available in clear or matched subtle tints'],
    ],
    engineeringTips: [
      'Metallic titanium pads never yellow or harden with skin oil exposure—making them the top choice for premium optical frames positioned for multi-year daily wear.',
      'For oversized metal or rimless sunglasses with heavier glass/CR-39 fronts, choose a wider pad footprint (e.g., 7×14 mm or 7.5×14 mm) to prevent bridge pressure marks.',
      'For acetate frames without metal pad arms, Kssmi also engineers integrated Low-Bridge (Asian Fit) or Standard-Bridge keyhole saddles during CNC front milling.',
    ],
    faqTitle: 'Nose Pads FAQ',
    faqs: [
      {
        question: 'Can Kssmi laser-engrave our brand monogram onto titanium nose pads?',
        answer: 'Yes. ET Metal Series titanium pads accept clean, permanent micro-laser monograms or icons—a discreet luxury detail that requires no physical mold.',
      },
      {
        question: 'How do I choose the right nose pad size (e.g., 6×12 vs. 7×14)?',
        answer: 'Smaller pads (5×10 to 6×12.5 mm) keep slim minimalist optical frames unobtrusive; larger pads (7×14 to 8×16.5 mm) distribute load comfortably on bolder sunglasses or heavier fronts.',
      },
      {
        question: 'Can nose pads be color-matched to our frame plating?',
        answer: 'Yes. Metal nose pads can be IP-plated to match your chosen KS-PL-* finish (gold, rose gold, silver, gunmetal, or black).',
      },
    ],
  },

  'carbon-fiber': {
    seoTitle: 'Custom Carbon Fiber & Glass Fiber Eyewear Swatches | Kssmi',
    seoDescription: 'Browse 91 carbon fiber and glass fiber composite eyewear laminates at Kssmi: 1K, 1.5K, 3K plain & twill weaves, colored aramid hybrids, and 200-pair MOQ composite frames.',
    heading: 'Carbon & Glass Fiber Swatches',
    intro: 'Select from 91 woven carbon fiber and colored glass-fiber composite laminates—including 1K, 1.5K, and 3K plain/twill weaves, metallic-wire hybrids, and aramid-infused performance sheets. Carbon-fiber models start at just 200 pairs per model.',
    quickPills: [
      '91 Composite Patterns (34 Carbon · 57 Glass Fiber)',
      '1K / 1.5K / 3K Plain & Twill Weaves',
      'Carbon Fiber MOQ: 200 Pairs / Model',
      'Gloss or Satin Clear Protective Coat',
    ],
    guideTitle: 'Composite Weave & Laminate Selection',
    guideSubtitle: 'Carbon and glass-fiber composites deliver extreme stiffness-to-weight ratios with visual depth baked directly into the structural laminate rather than surface paint.',
    tableHeaders: ['Composite Series', 'Visual & Tactile Character', 'Structural Strengths', 'Best Collection Positioning'],
    tableRows: [
      ['1K / 1.5K / 3K Carbon Weaves', 'Fine (1K) to bold (3K) geometric checkerboard or diagonal twill', 'Ultra-light mass, high flexural rigidity, heat stability', 'Performance-luxury sunglasses & minimalist technical optical'],
      ['Aramid & Metallic-Wire Hybrids', 'Carbon weave interlaced with red/blue/gold/silver accent threads', 'Distinct brand colorway embedded inside the weave itself', 'Automotive, motorsport & statement tech-luxury capsules'],
      ['Glass Fiber Color Laminates', 'Vibrant layered architectural striations & rich color bands', 'High strength with wider bright color possibilities than raw carbon', 'Bold contemporary fashion, youth & colorful lightweight ranges'],
    ],
    engineeringTips: [
      '1K and 1.5K weaves look finer and more refined on slim optical fronts and thin temples; 3K weaves showcase bold woven texture on wider sunglass silhouettes.',
      'Carbon-fiber frames carry Kssmi’s lowest custom MOQ at 200 pairs per model—making them an efficient way to launch a flagship halo capsule.',
      'Pair carbon-fiber fronts with beta-titanium or carbon-core temples and Nylon (PA) polarized lenses to maximize the ultra-lightweight wearing story.',
    ],
    faqTitle: 'Carbon & Glass Fiber FAQ',
    faqs: [
      {
        question: 'What is the MOQ for custom carbon-fiber eyewear models?',
        answer: 'Custom carbon-fiber models start at 200 pairs per model at Kssmi (compared with 300 pairs per model for standard acetate, metal, and titanium styles).',
      },
      {
        question: 'What is the visual difference between 1K, 1.5K, and 3K carbon fiber?',
        answer: 'The number refers to the filament count per woven tow (1,000 vs. 1,500 vs. 3,000 fibers). 1K creates a micro-fine, understated weave suited to slim optical frames; 3K produces a bolder, classic motorsport checkerboard or twill pattern.',
      },
      {
        question: 'Can carbon fiber be combined with titanium or acetate on the same frame?',
        answer: 'Yes. Hybrid constructions—such as carbon-fiber temples paired with a titanium front, or carbon-laminated acetate temples—are popular ways to balance featherweight comfort with tailored optician adjustability.',
      },
    ],
  },
};
