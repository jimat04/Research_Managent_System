"""Generate report artifacts only; never runs RMS, a browser, tests or SQL."""
from pathlib import Path
import json, zipfile, html, textwrap, subprocess, re, struct, zlib, hashlib
from xml.sax.saxutils import escape
import xml.etree.ElementTree as ET

OUT=Path(__file__).resolve().parent
SOURCE=json.loads((OUT/'report-source.json').read_text(encoding='utf-8'))
BLOCKS=SOURCE['blocks']
DIROUT=OUT/'diagrams'
DIROUT.mkdir(exist_ok=True)

def make_diagrams():
    specs=[]
    def spec(id,title):
        d={'id':id,'title':title,'width':960,'height':700,'nodes':[],'edges':[],'lanes':[]}
        specs.append(d);return d
    def node(d,id,x,y,text,w=240,h=76):d['nodes'].append(dict(id=id,x=x,y=y,w=w,h=h,text=text))
    def edge(d,points,label='',lx=0,ly=0):d['edges'].append(dict(points=points,label=label,labelX=lx,labelY=ly))
    d=spec('system-map','System map and persistence boundary')
    node(d,'public',45,45,'Public entry / archive\nAccount entry and public records')
    node(d,'people',360,45,'Student / faculty\nShared researcher workspace')
    node(d,'office',675,45,'Admin / authorized staff\nScoped review and record tools')
    node(d,'auth',360,195,'PHP handlers + shared helpers\nAuth / ownership / permissions')
    node(d,'core',45,350,'Core workflow records\nProjects, chapters, versions, reviews')
    node(d,'support',360,350,'Supporting records\nActivities, publications, IP')
    node(d,'services',675,350,'Shared / admin services\nMessages, files, reports, accounts')
    node(d,'store',280,545,'MySQL schema declarations + file storage\nInstalled state and data not inspected',400,85)
    edge(d,[[165,121],[165,233],[360,233]])
    edge(d,[[480,121],[480,195]])
    edge(d,[[795,121],[795,233],[600,233]])
    edge(d,[[400,271],[165,315],[165,350]])
    edge(d,[[480,271],[480,350]])
    edge(d,[[560,271],[795,315],[795,350]])
    edge(d,[[165,426],[165,500],[360,545]])
    edge(d,[[480,426],[480,545]])
    edge(d,[[795,426],[795,500],[600,545]])
    d=spec('lifecycle-swimlane','Title and chapter lifecycle swimlane')
    d['lanes']=[dict(x=30,width=260,text='Researcher (student / faculty)'),dict(x=350,width=260,text='System / persistence'),dict(x=670,width=260,text='Authorized admin / staff')]
    node(d,'draft',40,70,'Draft / revise title',240,62)
    node(d,'capture',360,70,'Submit -> locked version\nProject: submitted',240,62)
    node(d,'review',680,70,'Review latest submitted version',240,62)
    node(d,'revise',40,215,'Respond to title comments\nResubmit as a new version',240,70)
    node(d,'return',680,215,'Return -> comments required\nProject: for_revision',240,70)
    node(d,'approved',360,350,'Title approved\nProject: title_approved',240,68)
    node(d,'chapter',40,475,'Draft next eligible chapter\nTitle / predecessor gates apply',240,76)
    node(d,'chapterv',360,475,'Submit chapter -> locked version\nRepeat per chapter 1–5',240,76)
    node(d,'chapterreview',680,475,'Approve chapter OR return\nrevision_required -> revise',240,76)
    node(d,'outputs',360,610,'Completion VIEW + supporting outputs\nSee denominator caveat in report',240,68)
    edge(d,[[280,101],[360,101]])
    edge(d,[[600,101],[680,101]])
    edge(d,[[800,132],[800,215]])
    edge(d,[[680,250],[280,250]],'return',455,230)
    edge(d,[[160,215],[160,165],[480,165],[480,132]])
    edge(d,[[920,105],[945,105],[945,384],[600,384]],'approve',867,365)
    edge(d,[[360,384],[160,384],[160,475]])
    edge(d,[[280,513],[360,513]])
    edge(d,[[600,513],[680,513]])
    edge(d,[[800,551],[800,580],[160,580],[160,551]],'return / next approved chapter',340,583)
    edge(d,[[920,513],[935,513],[935,644],[600,644]],'approved progress',805,660)
    d=spec('state-transitions','Separate state domains')
    d['lanes']=[dict(x=20,width=920,text='Title project (canonical path; compatibility inputs omitted here)')]
    node(d,'tdraft',40,70,'draft',200,60);node(d,'tsub',380,70,'submitted',200,60);node(d,'tapp',720,70,'title_approved',200,60)
    node(d,'treturn',380,180,'for_revision',200,60)
    edge(d,[[240,100],[380,100]],'submit',290,80);edge(d,[[580,100],[720,100]],'approve',625,80)
    edge(d,[[480,130],[480,180]],'return',490,147)
    edge(d,[[380,210],[300,210],[300,100],[380,100]],'resubmit',240,151)
    node(d,'cdraft',40,325,'Chapter: draft / revision_required',240,68)
    node(d,'csub',360,325,'Chapter: submitted',240,68)
    node(d,'capp',680,325,'Chapter: approved',240,68)
    edge(d,[[280,359],[360,359]],'submit',290,338);edge(d,[[600,359],[680,359]],'approve',615,338)
    edge(d,[[480,393],[480,435],[160,435],[160,393]],'return -> revision_required',270,440)
    node(d,'vsub',40,525,'Version: submitted',240,64);node(d,'vapp',360,525,'Version: approved',240,64);node(d,'vret',680,525,'Version: returned',240,64)
    edge(d,[[280,557],[360,557]],'approve',287,535)
    edge(d,[[160,589],[160,625],[800,625],[800,589]],'return',440,630)
    d=spec('data-model','Focused data relationships (source schema, not live database)')
    node(d,'u',40,30,'users\nIdentity / role',240,64)
    node(d,'rp',360,30,'research_projects\ncreated_by / project_id',240,64)
    node(d,'pm',680,30,'project_members\nProject + user association',240,64)
    node(d,'ch',360,175,'chapters + chapter_content\nProject / chapter content',240,68)
    node(d,'v',40,325,'research_submission_versions\nProject + optional chapter FK',240,76)
    node(d,'rv',40,505,'research_submission_reviews\nVersion FK + reviewer',240,76)
    node(d,'up',360,325,'uploads + version_uploads\nVersion attachment links',240,76)
    node(d,'pub',680,175,'publications\nOptional research_project_id FK',240,68)
    node(d,'ip',680,505,'copyright_applications\nOptional project / publication FKs',240,76)
    node(d,'act',360,505,'research_activities\n+ activity_participants',240,76)
    edge(d,[[280,62],[360,62]],'creator FK',287,40)
    edge(d,[[600,62],[680,62]],'team',620,40)
    edge(d,[[480,94],[480,175]],'project',492,125)
    edge(d,[[360,62],[320,62],[320,280],[200,280],[200,325]],'project FK',222,260)
    edge(d,[[360,209],[120,209],[120,325]],'optional chapter FK',30,242)
    edge(d,[[160,401],[160,505]],'version FK',173,447)
    edge(d,[[280,363],[360,363]],'link',300,343)
    edge(d,[[600,62],[640,62],[640,140],[800,140],[800,175]],'optional project FK',678,150)
    edge(d,[[800,243],[800,505]],'optional publication FK',645,365)
    edge(d,[[600,62],[620,62],[620,468],[800,468],[800,505]],'optional project FK',682,450)
    return specs

SPECS=make_diagrams()
(DIROUT/'diagrams.json').write_text(json.dumps(SPECS,ensure_ascii=False,indent=2),encoding='utf-8')
for d in SPECS:
    svg=[f'<svg xmlns="http://www.w3.org/2000/svg" width="{d["width"]}" height="{d["height"]}" viewBox="0 0 960 700">',
         '<rect width="960" height="700" fill="white"/>',
         '<defs><marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0 0L8 4L0 8" fill="#66758a"/></marker></defs>']
    for lane in d['lanes']:
        svg.append(f'<text x="{lane["x"]+lane["width"]/2}" y="33" text-anchor="middle" font-family="Arial,sans-serif" font-size="13" fill="#172033">{escape(lane["text"])}</text>')
    for e in d['edges']:
        points=' '.join(f'{x},{y}' for x,y in e['points'])
        svg.append(f'<polyline points="{points}" fill="none" stroke="#66758a" stroke-width="1.5" marker-end="url(#arrow)"/>')
        if e['label']:svg.append(f'<text x="{e["labelX"]}" y="{e["labelY"]+11}" font-family="Arial,sans-serif" font-size="11" fill="#172033">{escape(e["label"])}</text>')
    for n in d['nodes']:
        svg.append(f'<rect x="{n["x"]}" y="{n["y"]}" width="{n["w"]}" height="{n["h"]}" rx="3" fill="#f2eefb" stroke="#7351ad"/>')
        lines=[]
        for text in n['text'].splitlines():lines+=textwrap.wrap(text,width=33)
        for i,line in enumerate(lines):
            y=n['y']+n['h']/2+(i-(len(lines)-1)/2)*17+4
            svg.append(f'<text x="{n["x"]+n["w"]/2}" y="{y}" text-anchor="middle" font-family="Arial,sans-serif" font-size="13" fill="#172033">{escape(line)}</text>')
    svg.append('</svg>')
    (DIROUT/(d['id']+'.svg')).write_text('\n'.join(svg),encoding='utf-8')

subprocess.run(['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',str(OUT/'render_diagrams.ps1'),'-ReportDirectory',str(OUT)],check=True,capture_output=True)

# Human-editable narrative export.
md=[]
for b in BLOCKS:
    if b['type']=='title':md.append('# '+b['text'].replace('\n',' '))
    elif b['type']=='heading':md.append('## '+b['text'])
    elif b['type']=='paragraph':md.append(b['text'])
    elif b['type']=='diagram':md.append(f"![{b['caption']}](diagrams/{b['id']}.svg)\n\n{b['caption']}")
    elif b['type']=='table':
        md.append('| '+' | '.join(b['headers'])+' |\n| '+' | '.join(['---']*len(b['headers']))+' |\n'+'\n'.join('| '+' | '.join(str(c).replace('|','/') for c in row)+' |' for row in b['rows']))
(OUT/'report.md').write_text('\n\n'.join(md)+'\n',encoding='utf-8')

# DOCX (native OOXML, no Office automation or external dependency).
W='http://schemas.openxmlformats.org/wordprocessingml/2006/main'
def column_fractions(headers):
    if len(headers)==2:return [.3,.7]
    if len(headers)!=3:return [1/len(headers)]*len(headers)
    return {
        'Actor':[.17,.415,.415],
        'Record':[.20,.30,.50],
        'View':[.17,.46,.37],
        'Action name':[.28,.30,.42],
        'Finding':[.21,.56,.23],
        'Paper area / capability':[.22,.60,.18],
        'Condition':[.24,.58,.18],
    }.get(headers[0],[.25,.50,.25])
def wp(text,style=None):
    props=f'<w:pPr><w:pStyle w:val="{style}"/></w:pPr>' if style else ''
    runs=''.join('<w:r><w:t xml:space="preserve">'+escape(line)+'</w:t></w:r>'+('<w:r><w:br/></w:r>' if i<len(text.splitlines())-1 else '') for i,line in enumerate(text.splitlines() or ['']))
    return '<w:p>'+props+runs+'</w:p>'
def doc_table(headers,rows):
    widths=[int(9360*f) for f in column_fractions(headers)]
    border='<w:tblBorders>'+''.join(f'<w:{k} w:val="single" w:sz="4" w:color="D8DDE7"/>' for k in ['top','left','bottom','right','insideH','insideV'])+'</w:tblBorders>'
    xml='<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/>'+border+'<w:tblCellMar><w:top w:w="90" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="90" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>'+''.join(f'<w:gridCol w:w="{v}"/>' for v in widths)+'</w:tblGrid>'
    for index,row in enumerate([headers]+rows):
        xml+='<w:tr><w:trPr><w:cantSplit/>'+('<w:tblHeader/>' if index==0 else '')+'</w:trPr>'
        for col,value in enumerate(row):
            xml+=f'<w:tc><w:tcPr><w:tcW w:w="{widths[col]}" w:type="dxa"/>'+('<w:shd w:fill="F2EEFB"/>' if index==0 else '')+'</w:tcPr>'+wp(str(value),'TableHeader' if index==0 else 'TableText')+'</w:tc>'
        xml+='</w:tr>'
    return xml+'</w:tbl>'+wp('')
def image_xml(id):
    return f'''<w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="5943600" cy="4333875"/><wp:docPr id="{id}" name="Report figure {id}"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Figure {id}"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rImage{id}"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="5943600" cy="4333875"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'''
body=[];images=[]
for b in BLOCKS:
    typ=b['type']
    if typ=='title':body.append(wp(b['text'],'Title'))
    elif typ=='heading':body.append(wp(b['text'],'Heading1'))
    elif typ=='paragraph':body.append(wp(b['text']))
    elif typ=='table':body.append(doc_table(b['headers'],b['rows']))
    elif typ=='diagram':
        images.append(b['id']);body.append(wp(b['caption'],'FigureTitle'));body.append(image_xml(len(images)))
namespaces=f'xmlns:w="{W}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
document=f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document {namespaces}><w:body>'+''.join(body)+'<w:sectPr><w:footerReference w:type="default" r:id="rFooter"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1273" w:bottom="1000" w:left="1273" w:header="400" w:footer="400"/></w:sectPr></w:body></w:document>'
styles=f'''<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="{W}">
<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="21"/><w:color w:val="172033"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="140" w:line="280" w:lineRule="auto"/><w:widowControl/></w:pPr></w:pPrDefault></w:docDefaults>
<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:pPr><w:spacing w:before="1100" w:after="500"/></w:pPr><w:rPr><w:b/><w:sz w:val="54"/><w:color w:val="51258C"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="Heading 1"/><w:pPr><w:keepNext/><w:pageBreakBefore/><w:spacing w:after="240"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="34"/><w:color w:val="51258C"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="TableText"><w:name w:val="Table text"/><w:pPr><w:spacing w:after="40" w:line="240"/></w:pPr><w:rPr><w:sz w:val="18"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="TableHeader"><w:name w:val="Table header"/><w:basedOn w:val="TableText"/><w:pPr><w:keepNext/></w:pPr><w:rPr><w:b/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="FigureTitle"><w:name w:val="Figure title"/><w:pPr><w:pageBreakBefore/><w:keepNext/><w:spacing w:after="200"/></w:pPr><w:rPr><w:b/><w:sz w:val="21"/></w:rPr></w:style></w:styles>'''
rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'+''.join(f'<Relationship Id="rImage{i}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/{id}.png"/>' for i,id in enumerate(images,1))+'</Relationships>'
footer=f'<w:ftr xmlns:w="{W}"><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:t>RMS v3 | Static inspection | 20 October 2026 | Page </w:t></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>'
with zipfile.ZipFile(OUT/'RMS-v3-As-Built-Report.docx','w',zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>')
    z.writestr('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rDocument" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
    z.writestr('word/document.xml',document);z.writestr('word/styles.xml',styles);z.writestr('word/_rels/document.xml.rels',rels);z.writestr('word/footer1.xml',footer)
    for id in images:z.write(DIROUT/(id+'.png'),'word/media/'+id+'.png')

# Static PDF layout with standard PDF fonts. No browser, print driver or Office process.
def ascii_text(s):
    return str(s).replace('→','->').replace('–','-').replace('—','-').replace('’',"'").replace('“','"').replace('”','"').encode('cp1252','replace').decode('cp1252')
def pdfstr(s):return ascii_text(s).replace('\\','\\\\').replace('(','\\(').replace(')','\\)')
# Standard Helvetica/Helvetica-Bold widths (1/1000 em); conservative maximum
# prevents bold headings and dense table text from escaping their columns.
WIDTHS={c:556 for c in '0123456789'}
WIDTHS.update(dict(zip('ABCDEFGHIJKLMNOPQRSTUVWXYZ',[722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611])))
WIDTHS.update(dict(zip('abcdefghijklmnopqrstuvwxyz',[556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500])))
WIDTHS.update({' ':278,'!':333,'"':474,'#':556,'$':556,'%':889,'&':722,"'":278,'(':333,')':333,'*':389,'+':584,',':278,'-':333,'.':278,'/':278,':':333,';':333,'<':584,'=':584,'>':584,'?':611,'@':1015,'[':333,'\\':278,']':333,'^':584,'_':556,'`':333,'{':389,'|':280,'}':389,'~':584})
def text_width(s,size):return sum(WIDTHS.get(c,667) for c in ascii_text(s))*size/1000
def wrap(s,width,size):
    lines=[]
    for para in ascii_text(s).splitlines() or ['']:
        line=''
        for word in para.split():
            if text_width(word,size)>width:
                pieces=textwrap.wrap(word,max(6,int(width/(size*.58))),break_long_words=True,break_on_hyphens=False)
            else:pieces=[word]
            for piece in pieces:
                candidate=(line+' '+piece).strip()
                if line and text_width(candidate,size)>width:lines.append(line);line=piece
                else:line=candidate
        lines.append(line)
    return lines

class PDF:
    def __init__(self):self.pages=[];self.cmd=[];self.y=0;self.section='';self.newpage()
    def text(self,x,y,s,size=10,bold=False,color=(.09,.12,.2)):
        self.cmd.append(f'{color[0]} {color[1]} {color[2]} rg BT /'+('F2' if bold else 'F1')+f' {size} Tf 1 0 0 1 {x:.2f} {842-y:.2f} Tm ({pdfstr(s)}) Tj ET')
    def line(self,x1,y1,x2,y2,color=(.8,.82,.87),width=.5):self.cmd.append(f'{color[0]} {color[1]} {color[2]} RG {width} w {x1:.2f} {842-y1:.2f} m {x2:.2f} {842-y2:.2f} l S')
    def rect(self,x,y,w,h,fill=(1,1,1)):
        self.cmd.append(f'{fill[0]} {fill[1]} {fill[2]} rg .8 .82 .87 RG .4 w {x:.2f} {842-y-h:.2f} {w:.2f} {h:.2f} re B')
    def newpage(self):
        if self.cmd:self.pages.append('\n'.join(self.cmd).encode('cp1252'))
        self.cmd=[];self.y=72
        self.text(48,31,'RMS v3 | As-built system and workflow',8,True,(.32,.15,.55))
        self.line(48,42,547,42)
        self.text(48,816,'Static source inspection only | 20 October 2026',8)
        self.text(510,816,str(len(self.pages)+1),8)
    def ensure(self,h):
        if self.y+h>785:self.newpage();return True
        return False
    def paragraph(self,text,size=10,bold=False):
        lines=wrap(text,499,size)
        if len(lines)*size*1.43<300:self.ensure(len(lines)*size*1.43+8)
        for line in lines:
            self.ensure(size*1.43);self.text(48,self.y,line,size,bold);self.y+=size*1.43
        self.y+=8
    def heading(self,text):
        self.newpage();self.section=text
        self.paragraph(text,18,True);self.y+=8
    def table(self,heads,rows):
        widths=[499*f for f in column_fractions(heads)]
        def row(values,header=False):
            cells=[wrap(t,w-14,8.8) for t,w in zip(values,widths)];height=max(map(len,cells))*12.5+14
            if self.ensure(height) and not header:row(heads,True)
            x=48
            for w,lines in zip(widths,cells):
                self.rect(x,self.y-10,w,height,(.95,.93,.98) if header else (1,1,1))
                for i,line in enumerate(lines):self.text(x+7,self.y+3+i*12.5,line,8.8,header)
                x+=w
            self.y+=height
        row(heads,True)
        for values in rows:row(values)
        self.y+=14
    def diagram(self,d,caption):
        self.newpage();self.paragraph(caption,11,True)
        scale=499/960;offset=self.y+8
        for lane in d['lanes']:self.text(48+(lane['x']+5)*scale,offset+30*scale,lane['text'],7,True)
        for edge in d['edges']:
            points=edge['points']
            for a,b in zip(points,points[1:]):self.line(48+a[0]*scale,offset+a[1]*scale,48+b[0]*scale,offset+b[1]*scale,(.4,.46,.54),.8)
            a,b=points[-2:];dx=b[0]-a[0];dy=b[1]-a[1];norm=(dx*dx+dy*dy)**.5
            if norm:
                dx/=norm;dy/=norm
                for sign in [-1,1]:self.line(48+b[0]*scale,offset+b[1]*scale,48+(b[0]-9*dx+sign*4*dy)*scale,offset+(b[1]-9*dy-sign*4*dx)*scale,(.4,.46,.54),.8)
            if edge['label']:self.text(48+edge['labelX']*scale,offset+(edge['labelY']+10)*scale,edge['label'],6)
        for n in d['nodes']:
            self.rect(48+n['x']*scale,offset+n['y']*scale,n['w']*scale,n['h']*scale,(.95,.93,.98))
            lines=wrap(n['text'],(n['w']-16)*scale,7)
            for i,line in enumerate(lines):
                x=48+(n['x']+n['w']/2)*scale-text_width(line,7)/2
                y=offset+(n['y']+n['h']/2)*scale+(i-(len(lines)-1)/2)*9+2
                self.text(x,y,line,7)
        self.y=offset+700*scale+24
        self.paragraph('Diagram is an implementation summary, not an observed execution trace. See accompanying section for exact predicates and limitations.',9)
    def save(self,path):
        if self.cmd:self.pages.append('\n'.join(self.cmd).encode('cp1252'));self.cmd=[]
        objs=[b'<< /Type /Catalog /Pages 2 0 R >>',b'',b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>']
        kids=[]
        for stream in self.pages:
            page_id=len(objs)+1;content_id=page_id+1;kids.append(f'{page_id} 0 R')
            objs.append(f'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {content_id} 0 R >>'.encode())
            compressed=zlib.compress(stream);objs.append(f'<< /Length {len(compressed)} /Filter /FlateDecode >>\nstream\n'.encode()+compressed+b'\nendstream')
        objs[1]=f'<< /Type /Pages /Count {len(kids)} /Kids [{" ".join(kids)}] >>'.encode()
        data=bytearray(b'%PDF-1.4\n%\xe2\xe3\xcf\xd3\n');offsets=[0]
        for i,o in enumerate(objs,1):offsets.append(len(data));data+=f'{i} 0 obj\n'.encode()+o+b'\nendobj\n'
        start=len(data);data+=f'xref\n0 {len(objs)+1}\n0000000000 65535 f \n'.encode()
        for off in offsets[1:]:data+=f'{off:010d} 00000 n \n'.encode()
        data+=f'trailer\n<< /Size {len(objs)+1} /Root 1 0 R >>\nstartxref\n{start}\n%%EOF\n'.encode();path.write_bytes(data)

pdf=PDF()
for b in BLOCKS:
    if b['type']=='title':pdf.y=150;pdf.paragraph(b['text'],30,True);pdf.y+=20
    elif b['type']=='heading':pdf.heading(b['text'])
    elif b['type']=='paragraph':pdf.paragraph(b['text'])
    elif b['type']=='table':pdf.table(b['headers'],b['rows'])
    elif b['type']=='diagram':pdf.diagram(next(d for d in SPECS if d['id']==b['id']),b['caption'])
pdf.save(OUT/'RMS-v3-As-Built-Report.pdf')

# Inspect generated artifacts only: XML well-formedness, text parity, image sizes.
with zipfile.ZipFile(OUT/'RMS-v3-As-Built-Report.docx') as z:
    for name in z.namelist():
        if name.endswith(('.xml','.rels')):ET.fromstring(z.read(name))
    xml=ET.fromstring(z.read('word/document.xml'))
    word_text=' '.join(xml.itertext())
    for b in BLOCKS:
        if b['type'] in ['heading','paragraph'] and b['text'] not in word_text:raise RuntimeError('Word content missing: '+b['text'][:60])
        if b['type']=='table':
            for cell in b['headers']+[cell for row in b['rows'] for cell in row]:
                if str(cell) not in word_text:raise RuntimeError('Word table text missing: '+str(cell)[:60])
for d in SPECS:
    raw=(DIROUT/(d['id']+'.png')).read_bytes()
    if raw[:8]!=b'\x89PNG\r\n\x1a\n' or struct.unpack('>II',raw[16:24])!=(1920,1400):raise RuntimeError('Unexpected diagram image')
review={'kind':'Generated document inspection, not application testing','source_blocks':len(BLOCKS),'pdf_pages':len(pdf.pages),
        'docx_xml':'Well-formed XML; report paragraph and heading text present','figures':len(SPECS),
        'exports':[{'file':p.name,'bytes':p.stat().st_size,'sha256':hashlib.sha256(p.read_bytes()).hexdigest()} for p in [OUT/'RMS-v3-As-Built-Report.docx',OUT/'RMS-v3-As-Built-Report.pdf']],
        'limits':'Word visual pagination is not checked through Office automation; PDF is generated statically. No application, browser, tests or database access.'}
(OUT/'artifact-review.json').write_text(json.dumps(review,indent=2),encoding='utf-8')
print(json.dumps(review,indent=2))
