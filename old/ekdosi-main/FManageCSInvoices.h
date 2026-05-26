//---------------------------------------------------------------------------

#ifndef FManageCSInvoicesH
#define FManageCSInvoicesH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "cxButtons.hpp"
#include "cxClasses.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
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
#include "cxStyles.hpp"
#include "cxTextEdit.hpp"
#include "JvExComCtrls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <ExtCtrls.hpp>
#include <ImgList.hpp>
#include <Menus.hpp>
#include <ToolWin.hpp>

#include "CNewSpecialForm.h"
#include "cxCalendar.hpp"
#include "cxDBEdit.hpp"
#include "cxDropDownEdit.hpp"
#include "cxMaskEdit.hpp"
#include "JvComCtrls.hpp"
#include "JvRollOut.hpp"
#include "cxNavigator.hpp"
#include <System.ImageList.hpp>
#include "DBAccess.hpp"
#include "MemDS.hpp"
#include "Uni.hpp"
#include <vector>
#include "CEditBox.h"
using namespace std;
//---------------------------------------------------------------------------
class TFrmManageCSInvoices : public NewSpecialForm
{
__published:	// IDE-managed Components
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolRefresh;
	TcxButton *cxButton1;
	TcxTextEdit *editSearch;
	TJvPanel *PanelMain;
	TcxGrid *GridsSInvoices;
	TcxGridDBTableView *ViewCsInvoices;
	TcxGridLevel *GridsSInvoicessLevel1;

//	TmySQLQuery *QueryInvoices;
	TDataSource *DSInvoices;
	TcxGridDBColumn *ViewCsInvoicesid;
	TcxGridDBColumn *ViewCsInvoicesinvoicenum;
	TcxGridDBColumn *ViewCsInvoicesdate;
	TcxGridDBColumn *ViewCsInvoicesduedate;
	TcxGridDBColumn *ViewCsInvoicesdatepaid;
	TcxGridDBColumn *ViewCsInvoicessubtotal;
	TcxGridDBColumn *ViewCsInvoicescredit;
	TcxGridDBColumn *ViewCsInvoicestotal;
	TcxGridDBColumn *ViewCsInvoicesstatus;
	TcxGridDBColumn *ViewCsInvoicespaymentmethod;
	TcxGridDBColumn *ViewCsInvoicesinvoiced;
	TJvRollOut *jvrltRollDetail;
	TJvPageControl *jvpgcntrl1;
	TTabSheet *ts1;
	TLabel *lbl1;
	TcxDBTextEdit *cxdbtxtdt1;
	TTabSheet *ts2;
	TLabel *Label1;
	TcxDBDateEdit *cxDBDateEdit1;
	TJvPanel *JvPanel2;
	TcxGrid *cxgrdGridCSInvoiceLines;
	TcxGridDBTableView *ViewCSInvLines;
	TcxGridLevel *cxgrdlvlLevel1InvoiceLines;
	TJvPanel *JvPanel3;
	TJvToolBar *JvToolBar2;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton8;
	TToolButton *ToolEditLines;
	TToolButton *ToolAcceptLines;
	TToolButton *ToolCancelLines;
	TToolButton *ToolButton2;
	TToolButton *ToolButton1;
	TcxGridDBColumn *ViewCsInvoicesfullname;
	TLabel *Label3;
	TLabel *Label5;
	TcxDBDateEdit *cxDBDateEdit3;
	TcxDBDateEdit *cxDBDateEdit5;
//	TmySQLQuery *QueryCSInvoiceLines;
	TDataSource *DSInvoiceLines;
	TcxGridDBColumn *ViewCSInvLinesid;
	TcxGridDBColumn *ViewCSInvLinestype;
	TcxGridDBColumn *ViewCSInvLinesdescription;
	TcxGridDBColumn *ViewCSInvLinesamount;
	TcxGridDBColumn *ViewCSInvLinesduedate;
	TToolButton *ToolEditInvoices;
	TToolButton *ToolAcceptInvoices;
	TToolButton *ToolCancelInvoices;
	TToolButton *ToolButton6;
	TcxButton *cxButton2;
	TUniQuery *QueryInvoices;
	TIntegerField *IntegerField1;
	TIntegerField *IntegerField2;
	TWideMemoField *WideMemoField1;
	TDateField *DateField1;
	TDateField *DateField2;
	TDateTimeField *DateTimeField1;
	TFloatField *FloatField1;
	TFloatField *FloatField2;
	TFloatField *FloatField3;
	TFloatField *FloatField4;
	TFloatField *FloatField5;
	TFloatField *FloatField6;
	TFloatField *FloatField7;
	TWideMemoField *WideMemoField2;
	TWideMemoField *WideMemoField3;
	TWideMemoField *WideMemoField4;
	TSmallintField *SmallintField1;
	TWideMemoField *WideMemoField5;
	TWideMemoField *WideMemoField6;
	TWideMemoField *WideMemoField7;
	TWideMemoField *WideMemoField8;
	TWideMemoField *WideMemoField9;
	TWideMemoField *WideMemoField10;
	TWideMemoField *WideMemoField11;
	TWideMemoField *WideMemoField12;
	TWideMemoField *WideMemoField13;
	TWideMemoField *WideMemoField14;
	TWideMemoField *WideMemoField15;
	TWideMemoField *WideMemoField16;
	TWideMemoField *WideMemoField17;
	TWideMemoField *WideMemoField18;
	TWideMemoField *WideMemoField19;
	TUniQuery *QueryCSInvoiceLines;
	TIntegerField *IntegerField3;
	TIntegerField *IntegerField4;
	TWideMemoField *WideMemoField20;
	TDateField *DateField3;
	TDateField *DateField4;
	TDateTimeField *DateTimeField2;
	TFloatField *FloatField8;
	TFloatField *FloatField9;
	TFloatField *FloatField10;
	TFloatField *FloatField11;
	TFloatField *FloatField12;
	TFloatField *FloatField13;
	TFloatField *FloatField14;
	TWideMemoField *WideMemoField21;
	TWideMemoField *WideMemoField22;
	TWideMemoField *WideMemoField23;
	TSmallintField *SmallintField2;
	TWideMemoField *WideMemoField24;
	TWideMemoField *WideMemoField25;
	TWideMemoField *WideMemoField26;
	TWideMemoField *WideMemoField27;
	TWideMemoField *WideMemoField28;
	TWideMemoField *WideMemoField29;
	TWideMemoField *WideMemoField30;
	TWideMemoField *WideMemoField31;
	TWideMemoField *WideMemoField32;
	TWideMemoField *WideMemoField33;
	TWideMemoField *WideMemoField34;
	TWideMemoField *WideMemoField35;
	TWideMemoField *WideMemoField36;
	TWideMemoField *WideMemoField37;
	TWideMemoField *WideMemoField38;
	void __fastcall QueryCustomersCalcFields(TDataSet *DataSet);
	void __fastcall editSearchPropertiesChange(TObject *Sender);
	void __fastcall cxButton1Click(TObject *Sender);
	void __fastcall ToolEditInvoicesClick(TObject *Sender);
	void __fastcall ToolAcceptInvoicesClick(TObject *Sender);
	void __fastcall cxButton2Click(TObject *Sender);
	void __fastcall ToolEditLinesClick(TObject *Sender);
	void __fastcall ToolAcceptLinesClick(TObject *Sender);
	void __fastcall ToolCancelInvoicesClick(TObject *Sender);
	void __fastcall ToolCancelLinesClick(TObject *Sender);
private:	// User declarations
public:		// User declarations
	__fastcall TFrmManageCSInvoices(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageCSInvoices *FrmManageCSInvoices;
//---------------------------------------------------------------------------
#endif
