<?php
/**
 * WGIMS Process Flowchart Generator
 * Produces a large, readable SVG image of the complete system process flow.
 */

// ─── Canvas dimensions ─────────────────────────────────────────────────────
$W = 2400;  // total SVG width
$H = 4200;  // total SVG height

$FONT  = 'Segoe UI, Arial, sans-serif';
$MONO  = 'Consolas, monospace';

// ─── Colour palette ────────────────────────────────────────────────────────
$C = [
    'bg'        => '#1a1f2e',
    'grid'      => '#252b3d',
    'white'     => '#ffffff',
    'muted'     => '#8892a4',
    'border'    => '#3a4255',

    // Node colours
    'start'     => ['fill'=>'#22c55e','stroke'=>'#16a34a','text'=>'#fff'],   // oval  – green
    'end'       => ['fill'=>'#22c55e','stroke'=>'#16a34a','text'=>'#fff'],   // oval  – green
    'step'      => ['fill'=>'#1e3a5f','stroke'=>'#3b82f6','text'=>'#e2e8f0'],// rect  – blue
    'admin'     => ['fill'=>'#312e81','stroke'=>'#818cf8','text'=>'#e0e7ff'],// rect  – purple (admin only)
    'system'    => ['fill'=>'#164e63','stroke'=>'#22d3ee','text'=>'#cffafe'],// rect  – teal  (auto)
    'decision'  => ['fill'=>'#78350f','stroke'=>'#f59e0b','text'=>'#fef3c7'],// diamond – amber
    'blocked'   => ['fill'=>'#7f1d1d','stroke'=>'#ef4444','text'=>'#fee2e2'],// rect  – red
    'report'    => ['fill'=>'#14532d','stroke'=>'#4ade80','text'=>'#dcfce7'],// rect  – green
    'arrow'     => '#94a3b8',
    'arrowyes'  => '#4ade80',
    'arrowno'   => '#f87171',
    'section'   => '#2d3348',
    'sectionborder' => '#4a5280',
];

// ─── Helpers ───────────────────────────────────────────────────────────────

function esc(string $s): string { return htmlspecialchars($s, ENT_XML1); }

function rect(float $x,float $y,float $w,float $h,array $col,string $label,string $sub='',$rx=8): string {
    global $FONT;
    $o  = '<g>';
    $o .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%d" fill="%s" stroke="%s" stroke-width="1.5"/>',
        $x,$y,$w,$h,$rx,$col['fill'],$col['stroke']);
    $cy = $y + $h/2 + ($sub ? -8 : 0);
    $o .= sprintf('<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="13" font-weight="600" text-anchor="middle" dominant-baseline="middle">%s</text>',
        $x+$w/2,$cy,$col['text'],$FONT,esc($label));
    if ($sub) {
        foreach (explode("\n",$sub) as $i=>$line) {
            $o .= sprintf('<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="11" text-anchor="middle" dominant-baseline="middle">%s</text>',
                $x+$w/2,$cy+16+$i*14,$col['text'],$FONT,esc($line));
        }
    }
    return $o.'</g>';
}

function oval(float $x,float $y,float $w,float $h,array $col,string $label): string {
    global $FONT;
    $cx=$x+$w/2; $cy=$y+$h/2; $rx=$w/2; $ry=$h/2;
    return sprintf('<ellipse cx="%.1f" cy="%.1f" rx="%.1f" ry="%.1f" fill="%s" stroke="%s" stroke-width="2"/>'.
        '<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="13" font-weight="700" text-anchor="middle" dominant-baseline="middle">%s</text>',
        $cx,$cy,$rx,$ry,$col['fill'],$col['stroke'],
        $cx,$cy,$col['text'],$FONT,esc($label));
}

function diamond(float $x,float $y,float $w,float $h,array $col,string $label,string $sub=''): string {
    global $FONT;
    $cx=$x+$w/2; $cy=$y+$h/2;
    $pts=sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
        $cx,$y,  $x+$w,$cy,  $cx,$y+$h,  $x,$cy);
    $o = sprintf('<polygon points="%s" fill="%s" stroke="%s" stroke-width="1.5"/>',$pts,$col['fill'],$col['stroke']);
    $o .= sprintf('<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="11" font-weight="600" text-anchor="middle" dominant-baseline="middle">%s</text>',
        $cx,$cy+($sub?-7:0),$col['text'],$FONT,esc($label));
    if ($sub) {
        $o .= sprintf('<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="10" text-anchor="middle" dominant-baseline="middle">%s</text>',
            $cx,$cy+9,$col['text'],$FONT,esc($sub));
    }
    return $o;
}

function arrow(float $x1,float $y1,float $x2,float $y2,string $col,string $label='',string $labelPos='mid'): string {
    global $FONT;
    $o  = sprintf('<defs><marker id="ah_%s" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto">'.
        '<polygon points="0 0, 8 3, 0 6" fill="%s"/></marker></defs>',
        md5("$x1$y1$x2$y2$col"),$col);
    $o .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1.8" marker-end="url(#ah_%s)"/>',
        $x1,$y1,$x2,$y2,$col,md5("$x1$y1$x2$y2$col"));
    if ($label) {
        $lx = $labelPos==='start' ? $x1+20 : ($labelPos==='end' ? $x2-10 : ($x1+$x2)/2);
        $ly = ($y1+$y2)/2 - 5;
        $o .= sprintf('<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="11" font-weight="600">%s</text>',
            $lx,$ly,$col,$FONT,esc($label));
    }
    return $o;
}

function arrowPath(string $d,string $col,string $label=''): string {
    global $FONT;
    $id = 'ap'.md5($d.$col);
    $o  = sprintf('<defs><marker id="%s" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto">'.
        '<polygon points="0 0, 8 3, 0 6" fill="%s"/></marker></defs>',$id,$col);
    $o .= sprintf('<path d="%s" stroke="%s" stroke-width="1.8" fill="none" marker-end="url(#%s)"/>',$d,$col,$id);
    return $o;
}

function sectionBg(float $x,float $y,float $w,float $h,string $title): string {
    global $C,$FONT;
    return sprintf(
        '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="12" fill="%s" stroke="%s" stroke-width="1" opacity="0.9"/>'.
        '<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="12" font-weight="700" text-transform="uppercase" letter-spacing="2">%s</text>',
        $x,$y,$w,$h,$C['section'],$C['sectionborder'],
        $x+16,$y+20,$C['muted'],$FONT,esc($title));
}

function label(float $x,float $y,string $text,string $col,int $size=11): string {
    global $FONT;
    return sprintf('<text x="%.1f" y="%.1f" fill="%s" font-family="%s" font-size="%d">%s</text>',
        $x,$y,$col,$FONT,$size,esc($text));
}

// ─── Build SVG ─────────────────────────────────────────────────────────────
$svg = '';

// Background
$svg .= sprintf('<rect width="%d" height="%d" fill="%s"/>',$W,$H,$C['bg']);

// Grid dots
for ($gx=0;$gx<=$W;$gx+=40) for ($gy=0;$gy<=$H;$gy+=40)
    $svg .= sprintf('<circle cx="%d" cy="%d" r="1" fill="%s"/>',$gx,$gy,$C['grid']);

// ═══════════════════════════════════════════════════════════════════
//  COLUMN CENTRES  (we lay out 4 main columns)
// ═══════════════════════════════════════════════════════════════════
$col = [260,700,1200,1700,2160];  // 5 x-centres for placing nodes
$nw  = 200;  $nh  = 52;  // default node width / height
$dw  = 160;  $dh  = 80;  // diamond size

// ─── TITLE ─────────────────────────────────────────────────────────────────
$svg .= '<text x="1200" y="44" fill="#e2e8f0" font-family="'.$FONT.'" font-size="22" font-weight="700" text-anchor="middle">WGIMS — Welfare Goods Inventory Management System</text>';
$svg .= '<text x="1200" y="68" fill="'.$C['muted'].'" font-family="'.$FONT.'" font-size="13" text-anchor="middle">Complete Process Flow · DSWD Region X</text>';

// ─────────────────────────────────────────────────────────────────────
//  SECTION 1 – AUTHENTICATION  (y 90 – 470)
// ─────────────────────────────────────────────────────────────────────
$svg .= sectionBg(80,90,2240,400,'1  Authentication & Access Control');

// Nodes
$nodes = [];

// START
$svg .= oval(1150,110,100,40,$C['start'],'START');

// Login screen
$svg .= rect(1090,185,$nw+20,$nh,$C['step'],'Login Screen','username + password');
$svg .= arrow(1200,150,1200,185,$C['arrow']);

// Valid credentials?
$svg .= diamond(1120,275,$dw,$dh,$C['decision'],'Valid?','credentials + active');
$svg .= arrow(1200,237,1200,275,$C['arrow']);

// Error – invalid
$svg .= rect(880,283,180,$nh,$C['blocked'],'Invalid Credentials','or account inactive');
$svg .= arrow(1120,315,1060,315,$C['arrowno']);
$svg .= label(1065,308,'NO',$C['arrowno']);
// back arrow
$svg .= arrowPath('M 970 283 L 970 210 L 1090 210',$C['arrowno']);

// Rate-limit block
$svg .= rect(600,283,200,$nh,$C['blocked'],'Rate Limited','10 attempts/min per IP');
$svg .= arrowPath('M 880 299 Q 760 299 800 299',$C['arrowno']);

// YES → role check
$svg .= diamond(1120,390,$dw,$dh,$C['decision'],'Role?','');
$svg .= arrow(1200,355,1200,390,$C['arrow']);
$svg .= label(1205,380,'YES',$C['arrowyes']);

// Admin/WM dashboard
$svg .= rect(930,398,200,$nh,$C['step'],'Admin / WM Dashboard','all warehouses & stats');
$svg .= arrow(1120,430,1130,430,$C['arrowyes']);
$svg .= label(1080,425,'Admin/WM',$C['arrowyes'],10);

// Center user dashboard
$svg .= rect(1300,398,200,$nh,$C['step'],'Center User Dashboard','assigned warehouses only');
$svg .= arrowPath('M 1280 430 L 1300 430',$C['arrowyes']);
$svg .= label(1283,425,'Other',$C['arrowyes'],10);

// No warehouse
$svg .= rect(1590,398,200,$nh,$C['blocked'],'No-Warehouse Notice','no data access');
$svg .= arrowPath('M 1500 430 L 1590 430',$C['arrowno']);

// ─────────────────────────────────────────────────────────────────────
//  SECTION 2 – MASTER DATA  (y 510 – 760)
// ─────────────────────────────────────────────────────────────────────
$svg .= sectionBg(80,510,2240,260,'2  Master Data Management  (Admin only)');

$svg .= rect(130,540,220,$nh,$C['admin'],'Item Categories','labels + account codes');
$svg .= rect(130,620,220,$nh,$C['admin'],'Item Catalog','description names per category');

$svg .= rect(430,540,220,$nh,$C['admin'],'Suppliers','name, contact, address');
$svg .= rect(430,620,220,$nh,$C['admin'],'Warehouses','name, code, active/inactive');
$svg .= rect(430,700,220,$nh,$C['admin'],'Users / Roles','Admin/WM/Custodian/Head/Staff');

$svg .= rect(730,580,200,$nh,$C['system'],'Catalog feeds','RIS item picker &','Subsidy item picker');

$svg .= arrow(350,566,430,566,$C['arrow']);
$svg .= arrow(350,646,730,597,$C['arrow']);
$svg .= arrow(650,566,730,590,$C['arrow']);

// Legend
$svg .= rect(1050,530,140,38,$C['step'],'Step / Action','');
$svg .= rect(1210,530,140,38,$C['admin'],'Admin Only','');
$svg .= rect(1370,530,140,38,$C['system'],'System Auto','');
$svg .= rect(1530,530,140,38,$C['blocked'],'Blocked / Error','');
$svg .= rect(1690,530,140,38,$C['report'],'Report / Output','');
$svg .= diamond(1850,520,120,58,$C['decision'],'Decision','');
$svg .= oval(2030,528,100,42,$C['start'],'Start / End');
$svg .= '<text x="1050" y="585" fill="'.$C['muted'].'" font-family="'.$FONT.'" font-size="11">Legend</text>';

// ─────────────────────────────────────────────────────────────────────
//  SECTION 3 – SUBSIDY / DELIVERY (inbound)  (y 790 – 1420)
// ─────────────────────────────────────────────────────────────────────
$svg .= sectionBg(80,790,2240,640,'3  Subsidy / Delivery Flow  (Inbound — goods arriving from supplier)');

// Stage 3a – Create subsidy
$sx = 130; $sy = 820;
$svg .= rect($sx,$sy,210,$nh,$C['step'],'Create Subsidy','RIS number, supplier, date');
$svg .= rect($sx,$sy+70,210,$nh,$C['step'],'Add Item Lines','qty requested per catalog item');
$svg .= arrow($sx+105,$sy+52,$sx+105,$sy+70,$C['arrow']);

$svg .= diamond($sx+25,$sy+155,160,$dh,$C['decision'],'Duplicate DR?','');
$svg .= arrow($sx+105,$sy+122,$sx+105,$sy+155,$C['arrow']);

$svg .= rect($sx,$sy+260,210,$nh,$C['system'],'SUB-000001 Generated','Status = PENDING');
$svg .= arrow($sx+105,$sy+235,$sx+105,$sy+260,$C['arrowno'],'NO',$C['arrowno']);
$svg .= arrowPath('M '.($sx+25).' '.($sy+195).' L '.($sx-30).' '.($sy+195).' L '.($sx-30).' '.($sy+286).' L '.($sx).' '.($sy+286),$C['arrowyes']);
$svg .= label($sx-28,$sy+188,'YES → suffix',$C['arrowyes'],10);

// Stage 3b – Record delivery
$dx = 440; $dy = 820;
$svg .= rect($dx,$dy,220,$nh,$C['step'],'Record Delivery Batch','warehouse, unit cost, ENGAS,');
$svg .= '<text x="'.($dx+110).'" y="'.($dy+42).'" fill="'.$C['step']['text'].'" font-family="'.$FONT.'" font-size="11" text-anchor="middle">expiry date, DR number</text>';

$svg .= diamond($dx+30,$dy+90,160,$dh,$C['decision'],'Over-deliver?','qty > remaining');
$svg .= arrow($dx+110,$dy+52,$dx+110,$dy+90,$C['arrow']);
$svg .= rect($dx,$dy+195,220,$nh,$C['blocked'],'REJECTED','over-delivery not allowed');
$svg .= arrow($dx+110,$dy+170,$dx+110,$dy+195,$C['arrowno'],'YES',$C['arrowno']);

$svg .= diamond($dx+30,$dy+290,160,$dh,$C['decision'],'Stock record','match all fields?');
$svg .= arrow($dx+110,$dy+90+$dh,$dx+110,$dy+290,$C['arrowyes'],'NO',$C['arrowyes']);
$svg .= label($dx+115,$dy+190,'NO → continue',$C['arrowyes'],10);

$svg .= rect($dx,$dy+395,220,$nh,$C['system'],'Reuse existing record','+= quantity');
$svg .= arrow($dx+110,$dy+370,$dx+110,$dy+395,$C['arrowyes'],'YES',$C['arrowyes']);

$svg .= rect($dx+250,$dy+395,220,$nh,$C['system'],'Create NEW record','new stock number (lock)');
$svg .= arrow($dx+110+80,$dy+330,$dx+350,$dy+395,$C['arrowno'],'NO',$C['arrowno']);

$svg .= rect($dx+80,$dy+480,160,$nh,$C['system'],'Write STOCK CARD','RECEIPT entry');
$svg .= arrow($dx+110,$dy+447,$dx+160,$dy+480,$C['arrow']);
$svg .= arrowPath('M '.($dx+370).' '.($dy+447).' L '.($dx+200).' '.($dy+480),$C['arrow']);

// Status update
$svg .= diamond($dx+30,$dy+565,160,70,$C['decision'],'Subsidy','status?');
$svg .= arrow($dx+160,$dy+532,$dx+160,$dy+565,$C['arrow']);

$svg .= rect($dx-50,$dy+650,120,40,$C['step'],'PENDING','nothing yet');
$svg .= rect($dx+100,$dy+650,120,40,$C['step'],'PARTIAL','some delivered');
$svg .= rect($dx+260,$dy+650,140,40,$C['report'],'FULLY DELIVERED','all received');

$svg .= arrow($dx+80,$dy+635,$dx+10,$dy+650,$C['arrowyes']);
$svg .= arrow($dx+160,$dy+635,$dx+160,$dy+650,$C['arrowyes']);
$svg .= arrow($dx+240,$dy+635,$dx+320,$dy+650,$C['arrowyes']);

// Connect subsidy create → delivery
$svg .= arrowPath('M '.($sx+210).' '.($sy+30).' L '.($dx).' '.($dy+30),$C['arrow']);
$svg .= label($sx+215,$sy+25,'after save',$C['muted'],10);

// ─────────────────────────────────────────────────────────────────────
//  SECTION 4 – STOCK RECORDS  (centre column, y 790 – 1420)
// ─────────────────────────────────────────────────────────────────────
$srx = 1050; $sry = 820;
$svg .= sectionBg($srx-20,$sry-15,360,640,'');
$svg .= '<text x="'.($srx+160).'" y="'.($sry+5).'" fill="'.$C['muted'].'" font-family="'.$FONT.'" font-size="11" text-anchor="middle">STOCK RECORDS (items table)</text>';
$svg .= rect($srx,$sry+20,320,80,$C['system'],'Item / Stock Record',
    "stock_number · warehouse · description\nunit_cost · engas_unit_cost · quantity\nexpiry_date · source_subsidy snapshot");
$svg .= arrowPath('M '.($dx+330).' '.($dy+30).' Q 900 '.($dy+30).' '.($srx).' '.($sry+60),$C['arrowyes']);

// Delivery → stock
$svg .= arrowPath('M '.($dx+330).' '.($dy+417).' Q 950 '.($dy+417).' '.($srx).' '.($sry+60),$C['arrowyes']);

// Stock card ledger
$svg .= rect($srx,$sry+140,320,70,$C['system'],'Stock Card Ledger',
    "Every receipt / issue / transfer in / out\nautomatically appended");
$svg .= arrow($srx+160,$sry+100,$srx+160,$sry+140,$C['arrow']);

// Reports box
$svg .= rect($srx,$sry+250,320,110,$C['report'],'Reports',
    "RPCI — physical count\nRSMI — issued supplies\nInventory Balance — live\nStock Card printouts");
$svg .= arrow($srx+160,$sry+210,$srx+160,$sry+250,$C['arrow']);

// Audit trail
$svg .= rect($srx,$sry+400,320,60,$C['system'],'Audit Trails',
    "Subsidy / RIS / Transfer correction logs");
$svg .= arrow($srx+160,$sry+360,$srx+160,$sry+400,$C['arrow']);

// ─────────────────────────────────────────────────────────────────────
//  SECTION 5 – RIS / REQUISITION (outbound)  (y 1460 – 2180)
// ─────────────────────────────────────────────────────────────────────
$svg .= sectionBg(80,1460,2240,700,'4  RIS / Requisition Flow  (Outbound — issuing goods to centers)');

$rx = 130; $ry = 1490;
$svg .= rect($rx,$ry,210,$nh,$C['step'],'Create RIS','purpose, date, catalog items');
$svg .= rect($rx,$ry+70,210,$nh,$C['system'],'IDs Generated','ris_code RIS-000001 (auto)','ris_number RIS-YYYYMM-#### (auto/custom)');
$svg .= arrow($rx+105,$ry+52,$rx+105,$ry+70,$C['arrow']);
$svg .= rect($rx,$ry+150,210,40,$C['step'],'Status = PENDING','no warehouse assigned yet');
$svg .= arrow($rx+105,$ry+122,$rx+105,$ry+150,$C['arrow']);

// Approval stage
$svg .= rect($rx,$ry+220,210,$nh,$C['step'],'Open RIS > Approve','choose warehouse + stock record');
$svg .= arrow($rx+105,$ry+190,$rx+105,$ry+220,$C['arrow']);

// Role check
$svg .= diamond($rx+25,$ry+305,160,70,$C['decision'],'Can approve?','Admin/WM/Head/Custodian');
$svg .= arrow($rx+105,$ry+272,$rx+105,$ry+305,$C['arrow']);
$svg .= rect($rx-50,$ry+400,120,40,$C['blocked'],'403 Blocked','Center Staff');
$svg .= arrow($rx+25,$ry+375,$rx,$ry+400,$C['arrowno'],'NO',$C['arrowno']);

// Stock checks
$svg .= diamond($rx+25,$ry+480,160,70,$C['decision'],'Stock record','belongs to warehouse?');
$svg .= arrow($rx+105,$ry+375+($dh-10),$rx+105,$ry+480,$C['arrowyes'],'YES',$C['arrowyes']);
$svg .= rect($rx-50,$ry+570,120,40,$C['blocked'],'REJECTED','wrong warehouse');
$svg .= arrow($rx+25,$ry+550,$rx,$ry+570,$C['arrowno'],'NO',$C['arrowno']);

$svg .= diamond($rx+25,$ry+640,160,70,$C['decision'],'Enough qty?','on exact record');
$svg .= arrow($rx+105,$ry+550,$rx+105,$ry+640,$C['arrowyes'],'YES',$C['arrowyes']);
$svg .= rect($rx-50,$ry+730,190,40,$C['blocked'],'REJECTED: Insufficient','no borrowing other records');
$svg .= arrow($rx+25,$ry+710,$rx,$ry+730,$C['arrowno'],'NO',$C['arrowno']);

// Dispatch
$isx = 440; $isy = $ry;
$svg .= rect($isx,$isy+640,220,$nh,$C['system'],'Create Dispatch Record','pins exact item_id, costs, expiry, DR#');
$svg .= arrow($rx+105,$ry+710,$isx,$isy+660,$C['arrowyes'],'YES',$C['arrowyes']);

$svg .= rect($isx,$isy+720,220,$nh,$C['system'],'Deduct Quantity','from exact stock record');
$svg .= arrow($isx+110,$isy+692,$isx+110,$isy+720,$C['arrow']);

$svg .= rect($isx,$isy+800,220,$nh,$C['system'],'Write STOCK CARD','ISSUE entry');
$svg .= arrow($isx+110,$isy+772,$isx+110,$isy+800,$C['arrow']);

// RIS status
$svg .= diamond($isx+30,$isy+895,160,70,$C['decision'],'All lines','fully issued?');
$svg .= arrow($isx+110,$isy+852,$isx+110,$isy+895,$C['arrow']);

$svg .= rect($isx-20,$isy+990,120,40,$C['step'],'APPROVED','fully fulfilled');
$svg .= rect($isx+120,$isy+990,160,40,$C['step'],'PARTIALLY_APPROVED','more issuances possible');
$svg .= rect($isx+320,$isy+990,120,40,$C['step'],'PENDING','nothing issued yet');

$svg .= arrow($isx+80,$isy+965,$isx+40,$isy+990,$C['arrowyes'],'All',$C['arrowyes'],10);
$svg .= arrow($isx+160,$isy+965,$isx+200,$isy+990,$C['arrowyes'],'Some',$C['arrowyes'],10);
$svg .= arrow($isx+240,$isy+965,$isx+380,$isy+990,$C['arrowno'],'None',$C['arrowno'],10);

// Print
$svg .= oval($isx+30,$isy+1060,160,40,$C['report'],'Print RIS Form');
$svg .= arrow($isx+110,$isy+1030,$isx+110,$isy+1060,$C['arrow']);

// stock record → issuance
$svg .= arrowPath('M '.($srx+160).' '.($sry+100).' L '.($srx+160).' '.($sry+620).' L '.($isx+220).' '.($sry+620).' L '.($isx+220).' '.($isy+640),$C['arrow']);
$svg .= label($srx+165,$sry+400,'available stock',$C['muted'],10);

// ─────────────────────────────────────────────────────────────────────
//  SECTION 6 – STOCK TRANSFERS  (y 2190 – 2900)
// ─────────────────────────────────────────────────────────────────────
$ty0 = 2190;
$svg .= sectionBg(80,$ty0,2240,680,'5  Stock Transfer Flow  (Warehouse A → Warehouse B)');

$tx = 130; $ty = $ty0+30;
$svg .= rect($tx,$ty,220,$nh,$C['step'],'Create Transfer','source wh, destination wh');
$svg .= diamond($tx+30,$ty+80,160,70,$C['decision'],'Same warehouse?','');
$svg .= arrow($tx+110,$ty+52,$tx+110,$ty+80,$C['arrow']);
$svg .= rect($tx-30,$ty+175,120,40,$C['blocked'],'REJECTED','must differ');
$svg .= arrow($tx+30,$ty+150,$tx,$ty+175,$C['arrowno'],'YES',$C['arrowno'],10);

$svg .= rect($tx,$ty+240,220,$nh,$C['step'],'Pick source stock records','& enter quantities + unit cost');
$svg .= arrow($tx+110,$ty+150,$tx+110,$ty+240,$C['arrowyes'],'NO',$C['arrowyes'],10);

$svg .= diamond($tx+30,$ty+330,160,70,$C['decision'],'Stock available?','on source record');
$svg .= arrow($tx+110,$ty+292,$tx+110,$ty+330,$C['arrow']);
$svg .= rect($tx-30,$ty+425,120,40,$C['blocked'],'REJECTED','insufficient stock');
$svg .= arrow($tx+30,$ty+400,$tx,$ty+425,$C['arrowno'],'NO',$C['arrowno'],10);

$svg .= rect($tx,$ty+490,220,$nh,$C['system'],'TRF-YYYY-NNNN Generated','Status = PENDING DISPATCH');
$svg .= arrow($tx+110,$ty+400,$tx+110,$ty+490,$C['arrowyes'],'YES',$C['arrowyes'],10);

$svg .= rect($tx,$ty+570,220,$nh,$C['system'],'Destination slot pre-created','preserves identity + subsidy');
$svg .= arrow($tx+110,$ty+542,$tx+110,$ty+570,$C['arrow']);

// Dispatch column
$dtx = 450; $dty = $ty0+30;
$svg .= rect($dtx,$dty,220,$nh,$C['step'],'Dispatch (partial OK)','enter qty to send now');
$svg .= diamond($dtx+30,$dty+80,160,70,$C['decision'],'Transfer done?','already completed');
$svg .= arrow($dtx+110,$dty+52,$dtx+110,$dty+80,$C['arrow']);
$svg .= rect($dtx-30,$dty+175,120,40,$C['blocked'],'BLOCKED','already complete');
$svg .= arrow($dtx+30,$dty+150,$dtx,$dty+175,$C['arrowno'],'YES',$C['arrowno'],10);

$svg .= rect($dtx,$dty+240,220,$nh,$C['system'],'Deduct from SOURCE','clamped ≥ 0');
$svg .= arrow($dtx+110,$dty+150,$dtx+110,$dty+240,$C['arrowyes'],'NO',$C['arrowyes'],10);

$svg .= rect($dtx,$dty+320,220,$nh,$C['system'],'Add to DESTINATION','subsidy identity preserved');
$svg .= arrow($dtx+110,$dty+292,$dtx+110,$dty+320,$C['arrow']);

$svg .= rect($dtx-30,$dty+400,140,$nh,$C['system'],'STOCK CARD','TRANSFER OUT (source)');
$svg .= rect($dtx+140,$dty+400,140,$nh,$C['system'],'STOCK CARD','TRANSFER IN (dest)');
$svg .= arrowPath('M '.($dtx+110).' '.($dty+372).' L '.($dtx+40).' '.($dty+400),$C['arrow']);
$svg .= arrowPath('M '.($dtx+110).' '.($dty+372).' L '.($dtx+210).' '.($dty+400),$C['arrow']);

$svg .= diamond($dtx+30,$dty+500,160,60,$C['decision'],'Status?','');
$svg .= arrow($dtx+110,$dty+468,$dtx+110,$dty+500,$C['arrow']);
$svg .= rect($dtx-30,$dty+585,120,40,$C['step'],'PARTIAL','');
$svg .= rect($dtx+120,$dty+585,140,40,$C['report'],'COMPLETED','');
$svg .= arrow($dtx+80,$dty+560,$dtx+10,$dty+585,$C['arrowyes'],'Some',$C['arrowyes'],10);
$svg .= arrow($dtx+180,$dty+560,$dtx+190,$dty+585,$C['arrowyes'],'All',$C['arrowyes'],10);

// connect create→dispatch
$svg .= arrowPath('M '.($tx+220).' '.($ty+516).' L '.($dtx).' '.($dty+30),$C['arrow']);
$svg .= label($tx+225,$ty+510,'after save',$C['muted'],10);

// ─────────────────────────────────────────────────────────────────────
//  SECTION 7 – CORRECTIONS  (y 2900 – 3360)
// ─────────────────────────────────────────────────────────────────────
$co0 = 2910;
$svg .= sectionBg(80,$co0,2240,420,'6  Admin Corrections & Reversals  (Admin only)');

// Subsidy correction
$cx=130; $cy=$co0+30;
$svg .= rect($cx,$cy,200,44,$C['admin'],'Edit Subsidy','header or line quantities');
$svg .= diamond($cx+20,$cy+70,160,60,$C['decision'],'Has deliveries?','');
$svg .= arrow($cx+100,$cy+44,$cx+100,$cy+70,$C['arrow']);
$svg .= rect($cx-30,$cy+155,120,40,$C['step'],'Full edit mode','RIS#, supplier, lines');
$svg .= rect($cx+110,$cy+155,140,40,$C['step'],'Correction mode','date/qty only; identity frozen');
$svg .= arrow($cx+60,$cy+130,$cx+10,$cy+155,$C['arrowno'],'NO',$C['arrowno'],10);
$svg .= arrow($cx+140,$cy+130,$cx+180,$cy+155,$C['arrowyes'],'YES',$C['arrowyes'],10);

$svg .= rect($cx,$cy+225,200,44,$C['admin'],'Edit One Delivery Batch','');
$svg .= rect($cx,$cy+295,200,44,$C['system'],'Cascade cost changes','through RIS + Transfer chains');
$svg .= rect($cx,$cy+365,200,44,$C['system'],'Replay running balances','all affected stock records');
$svg .= arrow($cx+100,$cy+269,$cx+100,$cy+295,$C['arrow']);
$svg .= arrow($cx+100,$cy+339,$cx+100,$cy+365,$C['arrow']);

// RIS correction
$rx2=450; $ry2=$co0+30;
$svg .= rect($rx2,$ry2,200,44,$C['admin'],'Edit One Dispatch Item','warehouse, qty, costs, DR#');
$svg .= rect($rx2,$ry2+70,200,44,$C['system'],'Credit OLD record','Debit NEW record (re-checked)');
$svg .= rect($rx2,$ry2+140,200,44,$C['system'],'Update stock card issue','entry or recreate if moved');
$svg .= rect($rx2,$ry2+210,200,44,$C['system'],'Replay balances both sides','resync RIS status');
$svg .= arrow($rx2+100,$ry2+44,$rx2+100,$ry2+70,$C['arrow']);
$svg .= arrow($rx2+100,$ry2+114,$rx2+100,$ry2+140,$C['arrow']);
$svg .= arrow($rx2+100,$ry2+184,$rx2+100,$ry2+210,$C['arrow']);

// Transfer correction
$tc=780; $tcy=$co0+30;
$svg .= rect($tc,$tcy,200,44,$C['admin'],'Edit Transfer Qty','increase or decrease');
$svg .= diamond($tc+20,$tcy+70,160,60,$C['decision'],'Direction?','');
$svg .= arrow($tc+100,$tcy+44,$tc+100,$tcy+70,$C['arrow']);
$svg .= rect($tc-40,$tcy+155,100,40,$C['step'],'Check source','has extra units?');
$svg .= rect($tc+140,$tcy+155,100,40,$C['step'],'Check dest','still holds units?');
$svg .= arrow($tc+60,$tcy+130,$tc+10,$tcy+155,$C['arrowno'],'Increase',$C['arrowyes'],10);
$svg .= arrow($tc+140,$tcy+130,$tc+190,$tcy+155,$C['arrowyes'],'Decrease',$C['arrowyes'],10);
$svg .= rect($tc,$tcy+225,200,44,$C['system'],'Adjust BOTH sides atomic','unit cost travels with stock');
$svg .= rect($tc,$tcy+295,200,44,$C['system'],'Reconcile card entries','newest-first, never negative');
$svg .= arrow($tc+60,$tcy+195,$tc+100,$tcy+225,$C['arrow']);
$svg .= arrow($tc+190,$tcy+195,$tc+100,$tcy+225,$C['arrow']);
$svg .= arrow($tc+100,$tcy+269,$tc+100,$tcy+295,$C['arrow']);

// ─────────────────────────────────────────────────────────────────────
//  SECTION 8 – REPORTS  (y 3360 – 3620)
// ─────────────────────────────────────────────────────────────────────
$ro0 = 3360;
$svg .= sectionBg(80,$ro0,2240,240,'7  Reports & Stock Cards');

$reps=[
    [130,'RPCI Report','Physical count of\nall active stock records'],
    [400,'RSMI Report','All items issued\nthrough RIS this period'],
    [670,'Inventory Balance','Live stock per warehouse\nwith ENGAS values'],
    [940,'Stock Card','Per-item running ledger\nreceipts / issues / transfers'],
    [1210,'Print RIS','Official 4-signature\nissue slip (PDF/print)'],
    [1480,'Print Transfer Slip','Official transfer record\n(PDF/print)'],
];
foreach ($reps as $r) {
    $svg .= rect($r[0],$ro0+30,200,80,$C['report'],$r[1],$r[2]);
    $svg .= arrowPath('M '.($srx+160).' '.($sry+300).' L '.($srx+160).' '.($ro0+70).' L '.($r[0]+100).' '.($ro0+70),$C['arrow']);
}

// ─────────────────────────────────────────────────────────────────────
//  SECTION 9 – LOGOUT  (y 3620 – 3800)
// ─────────────────────────────────────────────────────────────────────
$lo0=3640;
$svg .= sectionBg(80,$lo0,2240,200,'8  Logout & Session Protection');
$svg .= rect(400,$lo0+30,260,52,$C['step'],'Logout (POST)','Session destroyed, CSRF regenerated');
$svg .= rect(700,$lo0+30,260,52,$C['system'],'Clear-Site-Data header','browser cache cleared');
$svg .= oval(1020,$lo0+35,120,44,$C['end'],'Login Screen');
$svg .= arrow(660,$lo0+56,700,$lo0+56,$C['arrow']);
$svg .= arrow(960,$lo0+56,1020,$lo0+56,$C['arrow']);

// Session protection notes
$notes=[
    [400,$lo0+110,'Rate limit: 10 attempts / min per username + IP'],
    [400,$lo0+128,'Session ID regenerated at every login (session-fixation protection)'],
    [400,$lo0+146,'No-cache headers on all authenticated pages (back-button safe)'],
    [400,$lo0+164,'Deactivated accounts: force-logged-out on next request mid-session'],
];
foreach($notes as $n)
    $svg .= sprintf('<text x="%d" y="%d" fill="%s" font-family="%s" font-size="11">&#x2022; %s</text>',
        $n[0],$n[1],$C['muted'],$FONT,esc($n[2]));

// ─────────────────────────────────────────────────────────────────────
//  FOOTER
// ─────────────────────────────────────────────────────────────────────
$svg .= sprintf('<text x="1200" y="%d" fill="%s" font-family="%s" font-size="11" text-anchor="middle">WGIMS Process Flow &#xB7; DSWD Region X &#xB7; Generated %s</text>',
    $H-24, $C['muted'], $FONT, date('F Y'));

// ─── Write SVG file ─────────────────────────────────────────────────────────
$out = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$W}" height="{$H}" viewBox="0 0 {$W} {$H}">
{$svg}
</svg>
SVG;

file_put_contents(__DIR__.'/WGIMS-Process-Flow.svg', $out);
echo "SVG written to docs/WGIMS-Process-Flow.svg (".strlen($out)." bytes)\n";
