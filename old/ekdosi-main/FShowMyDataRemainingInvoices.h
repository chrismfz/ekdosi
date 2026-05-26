//---------------------------------------------------------------------------

#ifndef FShowMyDataRemainingInvoicesH
#define FShowMyDataRemainingInvoicesH
//---------------------------------------------------------------------------
#include <System.Classes.hpp>
#include <Vcl.Controls.hpp>
#include <Vcl.StdCtrls.hpp>
#include <Vcl.Forms.hpp>
#include "CNewSpecialForm.h"
#include "cxClasses.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxEdit.hpp"
#include "cxFilter.hpp"
#include "cxGraphics.hpp"
#include "cxGrid.hpp"
#include "cxGridCustomTableView.hpp"
#include "cxGridCustomView.hpp"
#include "cxGridDBTableView.hpp"
#include "cxGridLevel.hpp"
#include "cxGridTableView.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxNavigator.hpp"
#include "cxStyles.hpp"
#include <Data.DB.hpp>
#include <IBX.IBCustomDataSet.hpp>
#include "JvDotNetControls.hpp"
#include <IBX.IBQuery.hpp>
#include "JvExComCtrls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolBar.hpp"
#include <System.ImageList.hpp>
#include <Vcl.ComCtrls.hpp>
#include <Vcl.ExtCtrls.hpp>
#include <Vcl.ImgList.hpp>
#include <Vcl.ToolWin.hpp>
#include "cxCalendar.hpp"
#include "cxContainer.hpp"
#include "cxDateUtils.hpp"
#include "cxDropDownEdit.hpp"
#include "cxLabel.hpp"
#include "cxMaskEdit.hpp"
#include "cxTextEdit.hpp"
#include "dxCore.hpp"
#include "JvTimer.hpp"
#include "cxProgressBar.hpp"
#include "JvMenus.hpp"
#include <Vcl.Menus.hpp>
//---------------------------------------------------------------------------
class TFrmShowMyDataRemainingInvoices :  public NewSpecialForm
{
__published:	// IDE-managed Components
	TDataSource *DSInvoices;
	TIBDataSet *DatasetInvoice;
	TIntegerField *DatasetInvoiceINVOICE_ID;
	TIBStringField *DatasetInvoiceINVCODE;
	TIntegerField *DatasetInvoiceCUST_ID;
	TIBStringField *DatasetInvoiceINVTYPE;
	TDateField *DatasetInvoiceINVDATE;
	TSmallintField *DatasetInvoicePRINTED;
	TDateField *DatasetInvoiceDELIVERYDATE;
	TIntegerField *DatasetInvoiceDISTRAIM_ID;
	TIntegerField *DatasetInvoiceDELMETHOD_ID;
	TIntegerField *DatasetInvoicePAYMETH_ID;
	TIBBCDField *DatasetInvoicePRICE;
	TIBBCDField *DatasetInvoicePRICEWVAT;
	TIntegerField *DatasetInvoiceCONV_INVOICE_ID;
	TIBBCDField *DatasetInvoiceDISCOUNT;
	TTimeField *DatasetInvoiceINVTIME;
	TIBStringField *DatasetInvoiceADDRESS1;
	TIBStringField *DatasetInvoiceADDRESS2;
	TIBStringField *DatasetInvoiceCITY;
	TIBStringField *DatasetInvoicePOSTCODE;
	TIBStringField *DatasetInvoiceCOUNTRY;
	TIBStringField *DatasetInvoiceCUSTNAME;
	TIBStringField *DatasetInvoiceNOTES;
	TIntegerField *DatasetInvoiceCODE;
	TWideMemoField *DatasetInvoiceNOTES_OLD;
	TcxGrid *GridInvoices;
	TcxGridDBTableView *ViewInvoices;
	TcxGridDBColumn *ViewInvoicesINVOICE_ID;
	TcxGridDBColumn *ViewInvoicesINVCODE;
	TcxGridDBColumn *ViewInvoicesINVTYPE;
	TcxGridDBColumn *ViewInvoicesINVDATE;
	TcxGridDBColumn *ViewInvoicesCUSTNAME;
	TcxGridDBColumn *ViewInvoicesPRICE;
	TcxGridDBColumn *ViewInvoicesPRICEWVAT;
	TcxGridLevel *GridInvoicesLevel1;
	TJvDotNetButton *cmdSend;
	TSmallintField *DatasetInvoiceMYDATA_SENT;
	TDataSource *DSInvTypes;
	TIBQuery *QryInvTypes;
	TIBStringField *QryInvTypesINVTYPE_ID;
	TIBStringField *QryInvTypesNAME;
	TIBStringField *QryInvTypesFRM_FILENAME;
	TIBStringField *QryInvTypesEAFDSS_SCRIPT;
	TIntegerField *QryInvTypesINVCOUNT;
	TSmallintField *QryInvTypesSHOW_ON_MENU;
	TIBStringField *QryInvTypesPRINTER_NAME;
	TSmallintField *QryInvTypesPRINTER_NO;
	TIntegerField *QryInvTypesDISTAIM_ID;
	TIntegerField *QryInvTypesDELIVERYMETHOD_ID;
	TIntegerField *QryInvTypesPAYMETH_ID;
	TIntegerField *QryInvTypesCUST_ID;
	TSmallintField *QryInvTypesCREDITINVOICE;
	TSmallintField *QryInvTypesRETURNINVOICE;
	TIBStringField *QryInvTypesMYDATA_TYPE;
	TIBStringField *QryInvTypesMYDATA_INCOME_CLASS;
	TIBStringField *QryInvTypesMYDATA_INCOME_CLASS_CATEGORY;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *PanelSearch;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolRefresh;
	TcxDateEdit *EditFromDate;
	TcxDateEdit *EditToDate;
	TcxLabel *cxLabel1;
	TcxLabel *cxLabel2;
	TJvPanel *PanelBottomButtons;
	TStatusBar *StatusBar1;
	TJvTimer *TimerSearch;
	TcxLabel *lblLoading;
	TJvDotNetButton *JvDotNetButton1;
	TcxProgressBar *Progress;
	TcxComboBox *comboPeriod;
	TJvPopupMenu *JvPopupMenu1;
	TMenuItem *N1;
	TMenuItem *Suppress1;
	TJvDotNetButton *cmdSuppress;
	TcxComboBox *ComboState;
	TIBStringField *DatasetInvoiceCOMPANY_NAME;
	TIBStringField *DatasetInvoiceVAT_NO;
	TIBStringField *DatasetInvoiceVIES_VAT;
	TIBStringField *DatasetInvoiceOCCUPATION;
	TIBStringField *DatasetInvoiceEMAIL_SENT;
	TIBBCDField *DatasetInvoiceWITHHOLD_AMOUNT;
	TcxGridDBColumn *ViewInvoicesWITHHOLD_AMOUNT;
	void __fastcall cmdSendClick(TObject *Sender);
	void __fastcall ViewInvoicesCellDblClick(TcxCustomGridTableView *Sender, TcxGridTableDataCellViewInfo *ACellViewInfo,
          TMouseButton AButton, TShiftState AShift,
          bool &AHandled);
	void __fastcall DatasetInvoiceAfterOpen(TDataSet *DataSet);
	void __fastcall cmdRefreshClick(TObject *Sender);
	void __fastcall TimerSearchTimer(TObject *Sender);
	void __fastcall EditFromDatePropertiesChange(TObject *Sender);
	void __fastcall DatasetInvoiceBeforeOpen(TDataSet *DataSet);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall comboPeriodPropertiesChange(TObject *Sender);
	void __fastcall N1Click(TObject *Sender);
	void __fastcall Suppress1Click(TObject *Sender);
	void __fastcall cmdSuppressClick(TObject *Sender);
	void __fastcall ComboStatePropertiesChange(TObject *Sender);
private:	// User declarations
	TStringList *defaultSQL;
 bool sendInvoice(int _invoiceId, bool _printMessage = false);
public:		// User declarations
	__fastcall TFrmShowMyDataRemainingInvoices(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowMyDataRemainingInvoices *FrmShowRemaingingInvoices;
//---------------------------------------------------------------------------
#endif
