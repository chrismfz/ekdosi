//---------------------------------------------------------------------------

#ifndef FPrintH
#define FPrintH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "frxClass.hpp"
#include "frxIBXComponents.hpp"
#include "frxPreview.hpp"
#include "frxDMPExport.hpp"
#include "frxExportPDF.hpp"
#include "frxExportCSV.hpp"
#include "frxExportXLS.hpp"
#include "JvTimer.hpp"
#include "frxExportBaseDialog.hpp"

#include <vector>

#include "CNewSpecialForm.h"
using namespace std;
//---------------------------------------------------------------------------
class TFrmPrint : public NewSpecialForm
{
__published:	// IDE-managed Components
	TfrxReport *Report;
	TfrxPDFExport *PdfExport;
	TfrxDotMatrixExport *DotMatrixReport;
	TLabel *Label3;
	TfrxCSVExport *frxCSVExport1;
	TJvTimer *TimerPrint;
	TfrxIBXComponents *frxIBXComponents1;
	void __fastcall ReportAfterPrintReport(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall TimerPrintTimer(TObject *Sender);

private:	// User declarations
	AnsiString filename;
	vector<AnsiString> paramNames;
	vector<Variant> paramValues;
	AnsiString getPrinterName();
	AnsiString getInvCode(int invoiceId);
	bool isDotMatrix();
	void checkPdfExport();
	void checkBullZipPdf();
	Variant tmpId;
	bool invoicePrint;
	bool pdfExport;
	AnsiString pdfExportDir;
	long invoiceId;
	bool checkMyDataOnInvoice;

	public:		// User declarations
	__fastcall TFrmPrint(TComponent* Owner,TIBTransaction *trans, AnsiString filename,AnsiString _parameter, int _invoiceId, bool _printInvoice, bool _checkMyData = true);
	__fastcall TFrmPrint(TComponent* Owner,TIBTransaction *trans, int reportId);
	void addParameter(AnsiString _paramName, Variant _paramValue);
	void execute();
	void setCustomId(int _customId);
	void setCustomDate(TDate _date);
 bool checkMyData();
 public:
	void __fastcall closeDesignerEvent(TObject *Sender, TCloseAction &Action);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmPrint *FrmPrint;
//---------------------------------------------------------------------------
#endif
