<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    {{--
        CSWDO IDP MONITORING FORM (Phase 3 item 11a).

        A 1:1 REPRODUCTION of the photographed CSWDO sheet. The layout is the
        deliverable, not just the numbers: CSWDO files these, so a rearranged
        version is a different document to them. Every block below maps to the
        paper, in order:

          blue banner  "IDP Monitoring Form"
          six ruled rows, label + colon + full-width rule
          shaded block: the two NUMBER OF AFFECTED headers, then NO. OF
                        FAMILIES / NO. OF PERSONS, then NAME OF EVACUATION
                        CENTER and LOCATION / BARANGAY
          blue banner  "INSIDE EVACUATION CENTER", merged across the table
          orange header, seven age rows, right-aligned TOTAL row
          orange header, six category rows
          full-width rule
          Prepared by / Noted by

        The original is an Excel print, so the fills are the Office palette:
        #4472C4 for the banners, #F4B183 for the table headers, #DDD9C3 for the
        shaded block and #E8E8F0 for the strip above it.

        WHY BANNERS ARE TABLE ROWS. In the original they are merged cells. Making
        them merged cells here too guarantees they sit flush on the table header
        with no seam, which is what two stacked block elements cannot promise in
        dompdf.

        STYLING. dompdf ignores most modern CSS, so this is plain tables,
        percentage widths and background-color. No flex, no grid, no floats. The
        view does not load the app CSS; classes are local.

        ASCII ONLY. Every glyph is plain ASCII or an HTML entity in RAW markup.
        An entity must never be placed inside an escaped echo: e() encodes the
        ampersand and the entity prints as literal text.

        A4 PORTRAIT, set by RendersIdpForm. The generic report view is landscape;
        that setPaper() call must not be copied here.
    --}}
    <style>
        /* Doubled at-sign: Blade scans for directive-shaped tokens in style
           blocks too. @@ compiles to one literal at-sign, so dompdf gets a valid
           page rule and no future Blade version can claim the name. */
        @@page { margin: 10mm 9mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #000;
            font-size: 10px;
        }

        table { width: 100%; border-collapse: collapse; }

        /* ---- Blue title banner ---- */
        .banner {
            background-color: #4472C4;
            color: #FFFFFF;
            font-size: 15px;
            font-weight: bold;
            text-align: center;
            padding: 5px 0;
        }

        /* ---- Ruled header rows ---- */
        .hdr { margin-top: 10px; }
        .hdr td { font-size: 10px; padding: 0 0 1px; vertical-align: bottom; }
        .hdr .lbl { font-weight: bold; white-space: nowrap; padding-right: 4px; }
        .hdr .colon { width: 3%; }
        .hdr .rule { border-bottom: 1px solid #000; padding-left: 4px; }
        .hdr .spacer td { height: 10px; border: 0; }

        /* ---- Shaded affected block ---- */
        .affected { margin-top: 10px; }
        .affected td { font-size: 10px; padding: 6px; vertical-align: bottom; }
        .affected .strip {
            background-color: #E8E8F0;
            text-align: center;
            font-weight: bold;
            vertical-align: middle;
        }
        .affected .fill { background-color: #DDD9C3; font-weight: bold; }
        .affected .fill-rule {
            background-color: #DDD9C3;
            border-bottom: 1px solid #000;
            font-weight: bold;
        }

        /* ---- Data tables ---- */
        .grid td, .grid th { border: 1px solid #000; padding: 6px; font-size: 10px; }
        .grid .band {
            background-color: #4472C4;
            color: #FFFFFF;
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            padding: 4px 0;
        }
        .grid .head {
            background-color: #F4B183;
            text-align: center;
            font-weight: bold;
        }
        .grid .cat { text-align: left; }
        .grid .num { text-align: center; }
        .grid .tot-lbl { text-align: right; font-weight: bold; }
        .grid .tot-num { text-align: center; font-weight: bold; }

        .gap { height: 10px; }
        /* The shaded block sits flush on the blue banner in the original. */
        .gap-flush { height: 2px; }
        .divider { border-bottom: 1px solid #000; margin-top: 14px; }

        /* ---- Signatures ---- */
        .sign { margin-top: 26px; }
        .sign td { width: 50%; font-size: 10px; vertical-align: top; padding: 0; }
        .sign .role { padding-bottom: 38px; }
        .sign .line { width: 76%; border-bottom: 1px solid #000; font-size: 1px; }
        .sign .cap { width: 76%; text-align: center; padding-top: 2px; }
        .sign .cap-sub { width: 76%; text-align: center; }
        .sign .desig { width: 76%; text-align: center; font-weight: bold; padding-top: 2px; }

        .provenance { margin-top: 16px; font-size: 6px; color: #666; text-align: right; }
    </style>
</head>
<body>

<div class="banner">{{ $bannerTitle }}</div>

{{-- Header block: label, colon, and one rule running to the right margin. --}}
<table class="hdr">
    <tr>
        <td class="lbl" width="22%">NAME OF DISASTER</td>
        <td class="colon">:</td>
        <td class="rule">{{ $disasterName }}</td>
    </tr>
    <tr class="spacer"><td></td><td></td><td></td></tr>
    <tr>
        <td class="lbl">DATE OF DISASTER</td>
        <td class="colon">:</td>
        <td class="rule">{{ $disasterDate }}</td>
    </tr>
    <tr class="spacer"><td></td><td></td><td></td></tr>
    <tr>
        <td class="lbl">PROVINCE</td>
        <td class="colon">:</td>
        <td class="rule">{{ $form['province'] }}</td>
    </tr>
    <tr class="spacer"><td></td><td></td><td></td></tr>
    <tr>
        <td class="lbl">CITY / MUNICIPALITY</td>
        <td class="colon">:</td>
        <td class="rule">{{ $form['city'] }}</td>
    </tr>
    <tr class="spacer"><td></td><td></td><td></td></tr>
    <tr>
        <td class="lbl">BARANGAY</td>
        <td class="colon">:</td>
        <td class="rule">{{ $form['barangayName'] }}</td>
    </tr>
    <tr class="spacer"><td></td><td></td><td></td></tr>
    <tr>
        <td class="lbl">DATE &amp; TIME</td>
        <td class="colon">:</td>
        <td class="rule">{{ $generatedAt }}</td>
    </tr>
</table>

{{-- Shaded affected block. Blank rules print where a figure was cleared, so the
     sheet can be completed by hand rather than showing a misleading zero. --}}
<table class="affected">
    <tr>
        <td class="strip" colspan="2" width="50%">NUMBER OF<br>AFFECTED FAMILIES</td>
        <td class="strip" colspan="2" width="50%">NUMBER OF<br>AFFECTED PERSONS</td>
    </tr>
    <tr>
        <td class="fill" width="22%">NO. OF FAMILIES</td>
        <td class="fill-rule" width="28%">{{ $affectedFamilies }}</td>
        <td class="fill" width="22%">NO. OF PERSONS</td>
        <td class="fill-rule" width="28%">{{ $affectedPersons }}</td>
    </tr>
    <tr>
        <td class="fill" colspan="2">NAME OF EVACUATION CENTER</td>
        <td class="fill-rule" colspan="2">{{ $form['shelterName'] }}</td>
    </tr>
    <tr>
        <td class="fill" colspan="2">LOCATION / BARANGAY</td>
        <td class="fill-rule" colspan="2">{{ $form['shelterLocation'] }}</td>
    </tr>
</table>

<div class="gap-flush"></div>

{{-- Table 1. The banner and the column header are merged cells in the original,
     so they are rows here and cannot drift off the table edge. --}}
<table class="grid">
    <tr><td class="band" colspan="5">INSIDE EVACUATION CENTER</td></tr>
    <tr>
        <td class="head" width="23%">CATEGORY</td>
        <td class="head" width="20%">AGE</td>
        <td class="head" width="19%">MALE</td>
        <td class="head" width="19%">FEMALE</td>
        <td class="head" width="19%">TOTAL</td>
    </tr>
    @foreach($form['ageRows']['rows'] as $row)
        <tr>
            <td class="cat">{{ $row['category'] }}</td>
            <td class="cat">{{ $row['age'] }}</td>
            <td class="num">{{ $row['male'] }}</td>
            <td class="num">{{ $row['female'] }}</td>
            <td class="num">{{ $row['total'] }}</td>
        </tr>
    @endforeach
    <tr>
        <td class="tot-lbl" colspan="2">TOTAL</td>
        <td class="tot-num">{{ $form['ageRows']['totals']['male'] }}</td>
        <td class="tot-num">{{ $form['ageRows']['totals']['female'] }}</td>
        <td class="tot-num">{{ $form['ageRows']['totals']['total'] }}</td>
    </tr>
</table>

<div class="gap"></div>

{{-- Table 2. No TOTAL row on the paper form and none here: the categories
     overlap, so a column sum would be a meaningless figure on a signed sheet.
     The CATEGORY column is as wide as table 1's CATEGORY and AGE combined, so
     the MALE / FEMALE / TOTAL columns line up down the page. --}}
<table class="grid">
    <tr>
        <td class="head" width="43%">CATEGORY</td>
        <td class="head" width="19%">MALE</td>
        <td class="head" width="19%">FEMALE</td>
        <td class="head" width="19%">TOTAL</td>
    </tr>
    @foreach($form['categoryRows'] as $row)
        <tr>
            <td class="cat">{{ $row['category'] }}</td>
            <td class="num">{{ $row['male'] }}</td>
            <td class="num">{{ $row['female'] }}</td>
            <td class="num">{{ $row['total'] }}</td>
        </tr>
    @endforeach
</table>

<div class="divider"></div>

{{-- Signature block. Both names are handwritten after printing: whoever is on
     shift signs, and the system holds no register of names or designations.
     "Noted by" carries the OIC-CSWDO designation and no name, so a change of
     officer does not require a code change. --}}
<table class="sign">
    <tr>
        <td class="role">Prepared by:</td>
        <td class="role">Noted by:</td>
    </tr>
    <tr>
        <td>
            <div class="line">&nbsp;</div>
            <div class="cap">NAME</div>
            <div class="cap-sub">Position / Designation</div>
        </td>
        <td>
            <div class="line">&nbsp;</div>
            <div class="desig">OIC-CSWDO</div>
        </td>
    </tr>
</table>

<div class="provenance">
    EvacTech {{ $form['accumulated'] ? 'accumulated' : 'shelter' }} figures as of {{ $generatedAt }}
</div>

</body>
</html>
