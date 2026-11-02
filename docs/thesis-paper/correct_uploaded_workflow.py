"""Apply the agreed workflow corrections to the user's newly edited manuscript.

Only the identified text spans are replaced. Paragraph/run properties, surrounding
merged text, references, front matter and all other DOCX parts are preserved.
"""
from pathlib import Path
from xml.dom import minidom
import copy
import hashlib
import json
import re
import zipfile

HERE = Path(__file__).resolve().parent
SOURCE = HERE / 'new-chap-1-and-2.docx'
OUTPUT = HERE / 'new-chap-1-and-2-workflow-corrected.docx'
SOURCE_SHA256 = 'dc69106dcb955a066a63443983a763e3f08fb06a12779da4a2538e0001f678ce'
W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

# Body indexes refer to the newly supplied file, not an earlier generated copy.
# 'after' and 'through' delimit the affected passage in merged paragraphs.
BODY_EDITS = [
    (149, 'Introduction: progress visibility', '', 'It should also show chapter progress without giving every user review authority.',
     'Student and faculty researchers need clear visibility into their research-title and chapter submissions, review decisions, and recommendations. Both follow the same submission workflow. The system distinguishes editable drafts from locked submissions, preserves submission history, and displays chapter progress. Administrators and research staff with the required permissions perform formal review and approval.'),
    (196, 'Scope: users', 'Users  ', None,
     'The platform accommodates four account roles: Administrator, Research Staff, Faculty, and Student. Faculty and students participate as researchers and follow the same title-and-chapter submission workflow. Administrators and research staff perform administrative and decision-making tasks according to their permissions. Access to individual records depends on the applicable role permissions, record ownership, and project membership.'),
    (200, 'Scope: faculty', 'Faculty  ', None,
     'Creates and edits research-title drafts, submits titles for review, and submits Chapters 1–5 sequentially after title approval. Faculty researchers view decisions and recommendations, revise returned submissions, and resubmit their work through the same workflow used by student researchers. Submitted versions remain locked during review and after approval. Formal review and approval are performed by administrators and research staff with the required permissions.'),
    (201, 'Scope: student', 'Student  ', None,
     'Creates and edits research-title drafts, submits titles for review, and submits Chapters 1–5 sequentially after title approval. Student researchers view decisions and recommendations, revise returned submissions, and resubmit their work through the same workflow used by faculty researchers. Submitted versions remain locked during review and after approval. Formal review and approval are performed by administrators and research staff with the required permissions.'),
    (210, 'Scope: data covered within technology paragraph', 'Data Covered ', None,
     'The system manages account and profile records; research-title and chapter data; researcher ownership and project membership; submission snapshots and attachment references; review decisions and recommendations; research activities; publication records; intellectual-property and utilization records; in-app notifications; and activity logs. Access and modification rights follow the permissions and record-access rules implemented for each module.'),
    (223, 'Significance: faculty researchers', 'Faculty Researchers and Advisers.  ', None,
     'Provides a centralized interface for preparing research-title and chapter submissions, viewing decisions and recommendations, revising returned work, and monitoring recorded research progress. Faculty researchers use the same submission workflow as student researchers.'),
    (224, 'Significance: student researchers', 'Student Researchers.  ', None,
     'Provides a centralized interface for preparing research-title and chapter submissions, viewing decisions and recommendations, revising returned work, and monitoring recorded research progress. Student researchers use the same submission workflow as faculty researchers.'),
    (235, 'Definition: faculty within combined definitions', 'Faculty Researcher / Adviser.  ', 'formal title or chapter approval authority.',
     'A user with a faculty account who creates, submits, revises, and tracks research titles and chapters through the same workflow used by student researchers. The faculty role does not grant formal title or chapter review and approval authority.'),
    (243, 'Definition: student researcher', 'Student Researcher.  ', None,
     'A user with a student account who creates, submits, revises, and tracks research titles and chapters through the same workflow used by faculty researchers.'),
    (242, 'Definition: role-based access control', 'Role-Based Access Control.  ', None,
     'A mechanism that restricts application functions according to account roles and explicit action permissions. In RMS, faculty and students use the shared researcher workflow, while administrators and appropriately authorized research staff perform formal review and approval. Access to research records also follows the applicable ownership and project-membership rules.'),
    (266, 'Related literature: application processing', 'Application Processing. ', None,
     'PHP handles requests, authenticated sessions, permitted actions, validation, and document operations. Faculty and student researchers use the same title-and-chapter submission pages. Administrators and research staff with the required permissions review submitted versions and record decisions and recommendations. The application preserves submission history and enforces editing restrictions on submitted work.'),
    (284, 'Quality discussion: security', 'Security. ', None,
     'Verify that faculty and student researchers can perform permitted actions on eligible research records and cannot perform formal title or chapter review and approval. Check administrator and research-staff permissions, record ownership, project-membership access, direct-route document access, CSRF handling, input validation, and submitted-version locks. Respondent ratings alone cannot establish that these controls are effective.'),
    (327, 'Synthesis: access-control paragraph', '', None,
     'Fourth, role-based access-control research supports a clear distinction between research submission and formal decision-making authority. Faculty and students use the same researcher workflow, while administrators and research staff with the required permissions perform title and chapter review and approval. Security verification must include attempts to access other researchers’ records, modify locked submissions, or perform decisions without authorization.'),
    (345, 'Theoretical framework: application processing', 'Application Processing.  ', None,
     'PHP handles authorization, validation, document operations, submission locks, version capture, and review decisions. Faculty and students follow the same researcher workflow. Administrators and research staff exercise decision-making authority according to their permissions. Following the access-policy verification focus of Le et al. (2022), tests should compare implemented access rights with the intended permissions.'),
    (359, 'Conceptual framework narrative: input', 'Input. ', None,
     'Institutional documents and validated campus requirements; the needs of administrators, research staff, faculty researchers, and student researchers; the shared faculty/student title-and-chapter workflow; record-access rules and administrator/staff decision permissions; the implemented technology stack; supporting activity, publication, and intellectual-property functions; and the selected evaluation characteristics and baseline task definitions.'),
    (361, 'Conceptual framework narrative: output', 'Output.  ', None,
     'The implemented RMS provides a shared faculty/student title-and-chapter submission workflow, administrator and authorized research-staff review and approval, preserved submission versions, recommendations, revision history, chapter-progress indicators, research activities, publication and intellectual-property records, archive views, and administrative functions. Evaluation reports will identify the tested scope, observed results, unresolved defects, and limitations. No quality rating or measured improvement is presumed.'),
]


def children(node, local):
    return [x for x in node.childNodes if x.nodeType == x.ELEMENT_NODE and x.namespaceURI == W and x.localName == local]


def text_of(node):
    return ''.join(x.firstChild.data if x.firstChild else '' for x in node.getElementsByTagNameNS(W, 't'))


def set_text(node, value):
    for child in list(node.childNodes):
        node.removeChild(child)
    node.setAttribute('xml:space', 'preserve')
    node.appendChild(node.ownerDocument.createTextNode(value))


def replace_span(paragraph, old, new):
    """Replace one exact text span while retaining existing run properties."""
    assert old and text_of(paragraph).count(old) == 1, old[:90]
    before = text_of(paragraph)
    start = before.index(old)
    end = start + len(old)
    nodes = list(paragraph.getElementsByTagNameNS(W, 't'))
    spans = []
    pos = 0
    for node in nodes:
        value = node.firstChild.data if node.firstChild else ''
        spans.append((node, pos, pos + len(value), value))
        pos += len(value)
    touched = [(node, a, b, value) for node, a, b, value in spans if a < end and b > start]
    assert touched
    first, first_start, _, first_value = touched[0]
    prefix = first_value[:start - first_start]
    last, last_start, _, last_value = touched[-1]
    suffix = last_value[end - last_start:]
    for node, _, _, _ in touched:
        set_text(node, '')
    replacement = prefix + new + (suffix if first is last else '')
    lines = replacement.split('\n')
    set_text(first, lines[0])
    anchor = first
    for line in lines[1:]:
        br = paragraph.ownerDocument.createElementNS(W, 'w:br')
        anchor.parentNode.insertBefore(br, anchor.nextSibling)
        t = paragraph.ownerDocument.createElementNS(W, 'w:t')
        set_text(t, line)
        br.parentNode.insertBefore(t, br.nextSibling)
        anchor = t
    if first is not last:
        set_text(last, suffix)
    expected = before[:start] + new.replace('\n', '') + before[end:]
    assert text_of(paragraph) == expected


def path_to(node):
    path = []
    while node.parentNode is not None:
        path.append(list(node.parentNode.childNodes).index(node))
        node = node.parentNode
    return tuple(reversed(path))


def at_path(doc, path):
    for i in path:
        doc = doc.childNodes[i]
    return doc


def properties(paragraph):
    return ''.join(x.toxml() for x in children(paragraph, 'pPr'))


def masked_document(doc, edited_paths):
    masked = doc.cloneNode(True)
    for path in edited_paths:
        node = at_path(masked, path)
        for child in list(node.childNodes):
            if child.nodeType == child.ELEMENT_NODE and child.namespaceURI == W and child.localName == 'pPr':
                continue
            node.removeChild(child)
    return masked.toxml()


def main():
    original_hash = hashlib.sha256(SOURCE.read_bytes()).hexdigest()
    assert original_hash == SOURCE_SHA256, 'Source changed; recheck the uploaded manuscript before applying edits.'
    with zipfile.ZipFile(SOURCE) as archive:
        doc = minidom.parseString(archive.read('word/document.xml'))
        baseline = doc.cloneNode(True)
        body = doc.getElementsByTagNameNS(W, 'body')[0]
        paragraphs = children(body, 'p')
        tables = children(body, 'tbl')
        assert len(paragraphs) == 387 and len(tables) == 5
        changed = {}

        def remember(p, label):
            key = path_to(p)
            if key not in changed:
                changed[key] = {'location': label, 'before': text_of(p), 'properties': properties(p)}

        for index, label, after, through, new in BODY_EDITS:
            p = paragraphs[index]
            remember(p, label)
            current = text_of(p)
            if index in [210]:
                assert current.count(after) == 1
                begin = current.index(after) + len(after)
            else:
                assert current.startswith(after), label
                begin = len(after)
            end = current.index(through, begin) + len(through) if through else len(current)
            replace_span(p, current[begin:end], new)
            if index == 223:
                replace_span(p, 'Faculty Researchers and Advisers', 'Faculty Researchers')
            if index == 235:
                replace_span(p, 'Faculty Researcher / Adviser', 'Faculty Researcher')

        def cell_paragraph(table, row, cell):
            return children(children(children(tables[table], 'tr')[row], 'tc')[cell], 'p')[0]

        p = cell_paragraph(0, 5, 1)
        remember(p, 'Table 2.1: EARIST RMS documented capabilities')
        replace_span(p, text_of(p), 'Shared faculty/student title-and-chapter submissions; editable drafts; locked submitted versions; preserved submission history; administrator and authorized research-staff review and approval; and connected research records.')

        p = cell_paragraph(1, 1, 0)
        remember(p, 'Figure 2.1: architecture and authorization block')
        replace_span(p, 'Browser presentation → PHP processing → relational persistence.', 'Browser presentation → PHP processing → relational persistence.\nFaculty and students use the same title-and-chapter submission workflow.\nAdministrators and authorized research staff perform formal review and approval.')
        replace_span(p, 'Access-policy verification informed by Le et al. (2022); explicit permissions and record-access rules.', 'Access-policy verification is informed by Le et al. (2022).')

        p = cell_paragraph(2, 1, 0)
        remember(p, 'Figure 2.2: IPO input bullets')
        replace_span(p, '• Administrator, staff, faculty, and student tasks', '• Requirements of administrators, research staff, faculty researchers, and student researchers')
        replace_span(p, '• Shared title/chapter workflow and access rules', '• Shared faculty/student title-and-chapter workflow, record-access rules, and administrator/staff decision permissions')

        p = cell_paragraph(3, 1, 0)
        remember(p, 'Figure 2.2: IPO process steps 3 and 4')
        replace_span(p, '3. Develop connected persistent behavior', '3. Develop the shared faculty/student submission workflow and permission-controlled administrator/staff review actions.')
        replace_span(p, '4. Verify workflow, locks, history, invalid requests, and access', '4. Verify persistence, submission locks, sequential chapter eligibility, revision history, record access, and rejection of unauthorized actions.')

        p = cell_paragraph(4, 1, 0)
        remember(p, 'Figure 2.2: IPO expected-output bullet')
        replace_span(p, '• Title/chapter versions, authorized decisions, and revision history', '• Shared faculty/student title-and-chapter submissions, preserved versions, administrator/staff decisions, and revision history')

        assert len(changed) == 21
        # Verify the complete XML outside the 21 identified paragraphs is unchanged.
        assert masked_document(baseline, changed) == masked_document(doc, changed)
        for path, record in changed.items():
            assert properties(at_path(doc, path)) == record['properties']
            record['after'] = text_of(at_path(doc, path))
            record.pop('properties')
        # Specifically preserve the user's unrelated passages inside merged paragraphs.
        oldp = children(baseline.getElementsByTagNameNS(W, 'body')[0], 'p')
        for index, marker in [(149, '     Research metadata'), (235, ' ISO/IEC 25010')]:
            assert text_of(paragraphs[index]).split(marker, 1)[1] == text_of(oldp[index]).split(marker, 1)[1]
        assert text_of(paragraphs[210]).split('Data Covered ', 1)[0] == text_of(oldp[210]).split('Data Covered ', 1)[0]
        for i in list(range(141)) + list(range(374, 387)):
            assert paragraphs[i].toxml() == oldp[i].toxml()
        chapter_text = '\n'.join(text_of(p) for p in paragraphs[141:374])
        assert not re.search(r'advis(?:er|or|ee)', chapter_text, re.I)
        refs = [text_of(p) for p in paragraphs[375:]]
        years = [int(re.search(r'\((\d{4})\)', ref).group(1)) for ref in refs]
        assert len(refs) == 12 and all(2018 <= y <= 2026 for y in years)
        with zipfile.ZipFile(OUTPUT, 'w', compression=zipfile.ZIP_DEFLATED) as out:
            for part in archive.infolist():
                data = doc.toxml(encoding='UTF-8') if part.filename == 'word/document.xml' else archive.read(part.filename)
                out.writestr(copy.copy(part), data)
        with zipfile.ZipFile(OUTPUT) as out:
            assert out.testzip() is None
            for name in out.namelist():
                if name.endswith(('.xml', '.rels')):
                    minidom.parseString(out.read(name))
                if name != 'word/document.xml':
                    assert out.read(name) == archive.read(name), name
        assert hashlib.sha256(SOURCE.read_bytes()).hexdigest() == original_hash

    changes = [dict(record, xml_node_path=list(path)) for path, record in changed.items()]
    (HERE / 'new-chap-1-and-2-workflow-changes.json').write_text(json.dumps(changes, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    report = {
        'source': SOURCE.name,
        'output': OUTPUT.name,
        'source_sha256': original_hash,
        'output_sha256': hashlib.sha256(OUTPUT.read_bytes()).hexdigest(),
        'source_unchanged': True,
        'edited_locations': len(changed),
        'body_paragraphs_edited': len(BODY_EDITS),
        'table_paragraphs_edited': 5,
        'all_other_document_xml_unchanged': True,
        'paragraph_properties_preserved': True,
        'surrounding_merged_passages_preserved': True,
        'front_matter_preserved_including_thesis_adviser': True,
        'bibliography_preserved': True,
        'reference_count': len(refs),
        'publication_year_range': [min(years), max(years)],
        'unchanged_other_package_parts': len(archive.namelist()) - 1,
        'zip_crc_check': 'passed',
        'xml_parse_check': 'passed',
        'adviser_role_wording_in_chapters': 'removed',
        'application_or_database_changes': 'none',
        'rendered_word_layout': 'not verified; no Word automation launched',
    }
    (HERE / 'new-chap-1-and-2-workflow-validation.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
