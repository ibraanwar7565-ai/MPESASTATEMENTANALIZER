"""Builds sample/sample_statement.pdf (password 123456) from sample/sample_statement.csv.
The layout follows the Safaricom M-PESA full statement. Requires: pip install reportlab, and qpdf."""
import csv, subprocess, sys, os
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Spacer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
src = os.path.join(ROOT, "sample", "sample_statement.csv")
plain = os.path.join(ROOT, "storage", "tmp", "plain_statement.pdf")
out = os.path.join(ROOT, "sample", "sample_statement.pdf")

rows = list(csv.DictReader(open(src, encoding="utf-8")))
rows.reverse()  # statements list the newest transaction first
ss = getSampleStyleSheet()
small = ParagraphStyle("s", parent=ss["Normal"], fontSize=7, leading=8.5)
head = ParagraphStyle("h", parent=ss["Normal"], fontSize=7, leading=8.5, textColor=colors.white, fontName="Helvetica-Bold")

def money(v, neg=False):
    if not v:
        return ""
    s = f"{float(v):,.2f}"
    return "-" + s if neg else s

story = [Paragraph("<b>M-PESA STATEMENT</b>", ss["Title"]),
         Paragraph("Customer Name: BRIAN OTIENO &nbsp;&nbsp; Mobile Number: 0712345678", ss["Normal"]),
         Paragraph("Statement Period: 01 Oct 2026 - 21 Oct 2026 &nbsp;&nbsp; Request Date: 22 Oct 2026", ss["Normal"]),
         Spacer(1, 6 * mm),
         Paragraph("<b>DETAILED STATEMENT</b>", ss["Heading4"])]
data = [[Paragraph(h, head) for h in ["Receipt No.", "Completion Time", "Details", "Transaction Status", "Paid In", "Withdrawn", "Balance"]]]
for r in rows:
    data.append([r["Receipt No"], r["Completion Time"], Paragraph(r["Details"], small), r["Transaction Status"],
                 money(r["Paid In"]), money(r["Withdrawn"], True), money(r["Balance"])])
t = Table(data, colWidths=[20 * mm, 27 * mm, 62 * mm, 18 * mm, 19 * mm, 19 * mm, 21 * mm], repeatRows=1)
t.setStyle(TableStyle([
    ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#3a8c3f")),
    ("FONTSIZE", (0, 1), (-1, -1), 7),
    ("GRID", (0, 0), (-1, -1), 0.25, colors.HexColor("#bbbbbb")),
    ("VALIGN", (0, 0), (-1, -1), "TOP"),
    ("ALIGN", (4, 1), (-1, -1), "RIGHT"),
]))
story.append(t)
story.append(Spacer(1, 6 * mm))
story.append(Paragraph("Disclaimer: This is a sample statement generated for testing PesaGuard. Names and numbers are fictitious.", small))
SimpleDocTemplate(plain, pagesize=A4, leftMargin=10 * mm, rightMargin=10 * mm, topMargin=12 * mm, bottomMargin=12 * mm).build(story)
subprocess.run(["qpdf", "--encrypt", "123456", "owner-" + "123456", "256", "--", plain, out], check=True)
os.remove(plain)
print("Wrote", out)
