//---------------------------------------------------------------------------

#ifndef FDBParamsH
#define FDBParamsH
#include "JvCheckBox.hpp"
#include "JvCombobox.hpp"
#include "JvComCtrls.hpp"
#include "JvDialogs.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExComCtrls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExMask.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolEdit.hpp"
#include <Classes.hpp>
#include <ComCtrls.hpp>
#include <Controls.hpp>
#include <DB.hpp>
#include <Dialogs.hpp>
#include <ExtCtrls.hpp>
#include <Mask.hpp>
#include <SqlExpr.hpp>
#include <StdCtrls.hpp>
#include <WideStrings.hpp>
#include <Vcl.ActnList.hpp>

#include "CNewSpecialForm.h"
#include <IBX.IBDatabase.hpp>
#include <System.Actions.hpp>
#include <Data.DBXMySQL.hpp>
#include "cxCheckBox.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxDropDownEdit.hpp"
#include "cxMaskEdit.hpp"
#include "cxTextEdit.hpp"
//---------------------------------------------------------------------------

//---------------------------------------------------------------------------
class TFrmDBParams : public NewSpecialForm
{
__published:	// IDE-managed Components
	TStatusBar *StatusBar1;
	TIBDatabase *Database1;
	TJvDotNetButton *cmdOk;
	TJvDotNetButton *cmdCancel;
	TJvColorDialog *DialogColor;
	TJvPanel *PanelMain;
	TJvPageControl *TabParameters;
	TTabSheet *SheetDatabase;
	TLabel *LblPasswd;
	TLabel *LblUsername;
	TLabel *LblPath;
	TLabel *LblHostname;
	TJvDotNetButton *cmdTest;
	TJvDotNetEdit *EditUsername;
	TJvDotNetEdit *EditPath;
	TJvDotNetEdit *EditPassword;
	TJvDotNetEdit *EditHostname;
	TTabSheet *SheetColors;
	TLabel *LblPriCol;
	TLabel *LblSelCol;
	TLabel *LblSecCol;
	TJvPanel *PanelSelected;
	TJvPanel *PanelSecondary;
	TJvPanel *PanelPrimary;
	TTabSheet *TabSheet1;
	TPrinterSetupDialog *PrinterDialog;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetEdit *editPrinter;
	TTabSheet *TabSheet2;
	TLabel *Label1;
	TJvComboBox *comboSelReserve;
	TLabel *Label2;
	TJvCheckBox *checkDotMatrix;
	TTabSheet *TabBullZip;
	TLabel *Label3;
	TJvDirectoryEdit *editDirPdfInstallation;
	TLabel *Label4;
	TJvDirectoryEdit *editDirPdfSave;
	TJvCheckBox *checkBullZip;
	TTabSheet *TabSheet3;
	TJvCheckBox *checkCsCartSync;
	TLabel *Label5;
	TLabel *Label6;
	TLabel *Label7;
	TLabel *Label8;
	TJvDotNetButton *cmdMySqlTest;
	TJvDotNetEdit *editMyUsername;
	TJvDotNetEdit *editMyDbName;
	TJvDotNetEdit *editMyPassword;
	TJvDotNetEdit *editMyHostname;
	TSQLConnection *sqlConnection;
	TLabel *Label9;
	TJvComboBox *comboGridPricesWVat;
	TLabel *Label10;
	TActionList *ActionList1;
	TAction *Action1;
	TTabSheet *TabSheet4;
	TJvDotNetEdit *txtAadeUser;
	TLabel *Label11;
	TLabel *Label12;
	TJvDotNetEdit *txtAadeKey;
	TcxCheckBox *checkPdfExport;
	TLabel *Label13;
	TJvDirectoryEdit *editDirExportPdf;
	TLabel *lblAFM;
	TJvDotNetEdit *txtAfm;
	TcxComboBox *comboDevEnv;
	TLabel *Label14;
	void __fastcall FormCreate(TObject *Sender);
	void __fastcall FormClose(TObject *Sender, TCloseAction &Action);
	void __fastcall Button3Click(TObject *Sender);
	void __fastcall cmdOkClick(TObject *Sender);
	void __fastcall cmdTestClick(TObject *Sender);
	void __fastcall PanelMouseDown(TObject *Sender,
	TMouseButton Button, TShiftState Shift, int X, int Y);
	void __fastcall PanelMouseUp(TObject *Sender,
    TMouseButton Button, TShiftState Shift, int X, int Y);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall checkBullZipClick(TObject *Sender);
	void __fastcall checkCsCartSyncClick(TObject *Sender);
	void __fastcall cmdMySqlTestClick(TObject *Sender);
	void __fastcall Action1Execute(TObject *Sender);


private:	// User declarations
		AnsiString dbPassword;
		bool isLocal;
		void loadParameters();
		void saveParameters();
		void showMessage(AnsiString _message, AnsiString AppName, UINT btnType);
public:		// User declarations
        __fastcall TFrmDBParams(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmDBParams *FrmDBParams;
//---------------------------------------------------------------------------
#endif
