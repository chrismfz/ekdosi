//---------------------------------------------------------------------------

#ifndef FManageCSUsersH
#define FManageCSUsersH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>

#include "CNewSpecialForm.h"
#include "cxButtons.hpp"
#include "cxCalendar.hpp"
#include "cxClasses.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxDBEdit.hpp"
#include "cxDropDownEdit.hpp"
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
#include "cxMaskEdit.hpp"
#include "cxStyles.hpp"
#include "cxTextEdit.hpp"
#include "JvComCtrls.hpp"
#include "JvExComCtrls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvRollOut.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <ExtCtrls.hpp>
#include <ImgList.hpp>
#include <Menus.hpp>
#include <ToolWin.hpp>
#include "cxNavigator.hpp"
#include "DBAccess.hpp"
#include "MemDS.hpp"
#include "Uni.hpp"
#include <System.ImageList.hpp>


#include <vector>
#include "CEditBox.h"
using namespace std;
//---------------------------------------------------------------------------
class TFrmManageCSUsers : public NewSpecialForm
{
__published:	// IDE-managed Components
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TJvPanel *PanelMain;
	TcxGrid *GridCSInvoices;
	TcxGridDBTableView *ViewCSCustomers;
	TcxGridLevel *GridCSInvoicessLevel1;
	TJvPanel *PanelTop;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolRefresh;
	TDataSource *DSClients;
	TcxGridDBColumn *ViewCSCustomersid;
	TcxGridDBColumn *ViewCSCustomerscompanyname;
	TcxGridDBColumn *ViewCSCustomersaddress1;
	TcxGridDBColumn *ViewCSCustomerscity;
	TcxGridDBColumn *ViewCSCustomerspostcode;
	TcxGridDBColumn *ViewCSCustomersphonenumber;
	TcxGridDBColumn *ViewCSCustomerscredit;
	TJvRollOut *jvrltRollDetail;
	TJvPageControl *jvpgcntrl1;
	TTabSheet *ts1;
	TLabel *lbl1;
	TLabel *lbl2;
	TLabel *lbl3;
	TLabel *lbl6;
	TLabel *lbl7;
	TLabel *lbl8;
	TcxDBTextEdit *cxdbtxtdt1;
	TcxDBTextEdit *cxdbtxtdt2;
	TcxDBTextEdit *cxdbtxtdt3;
	TcxDBTextEdit *cxdbtxtdt6;
	TcxDBTextEdit *cxdbtxtdt7;
	TcxDBTextEdit *cxdbtxtdt8;
	TcxDBTextEdit *cxdbtxtdt9;
	TTabSheet *ts2;
	TcxGrid *cxgrdGridCSInvoiceLines;
	TcxGridDBTableView *ViewUsersProducts;
	TcxGridLevel *cxgrdlvlLevel1InvoiceLines;
	TDataSource *DSProducts;
	TcxGridDBColumn *ViewUsersProductsid;
	TcxGridDBColumn *ViewUsersProductspackageid;
	TcxGridDBColumn *ViewUsersProductsserver;
	TcxGridDBColumn *ViewUsersProductsdomain;
	TTabSheet *TabSheet1;
	TDataSource *DSDomains;
	TcxGrid *cxGrid1;
	TcxGridDBTableView *ViewCSDomains;
	TcxGridLevel *cxGridLevel1;
	TcxGridDBColumn *ViewCSDomainsid;
	TcxGridDBColumn *ViewCSDomainstype;
	TcxGridDBColumn *ViewCSDomainsregistrationdate;
	TcxGridDBColumn *ViewCSDomainsdomain;
	TcxGridDBColumn *ViewCSDomainsfirstpaymentamount;
	TcxGridDBColumn *ViewCSDomainsrecurringamount;
	TcxGridDBColumn *ViewCSDomainsregistrar;
	TcxGridDBColumn *ViewCSDomainsregistrationperiod;
	TcxGridDBColumn *ViewCSDomainsexpirydate;
	TcxGridDBColumn *ViewCSDomainssubscriptionid;
	TcxGridDBColumn *ViewCSDomainspromoid;
	TcxGridDBColumn *ViewCSDomainsstatus;
	TcxGridDBColumn *ViewCSDomainsnextduedate;
	TcxGridDBColumn *ViewCSDomainsnextinvoicedate;
	TcxGridDBColumn *ViewCSDomainsadditionalnotes;
	TcxGridDBColumn *ViewCSDomainspaymentmethod;
	TcxGridDBColumn *ViewCSDomainsdnsmanagement;
	TcxGridDBColumn *ViewCSDomainsemailforwarding;
	TcxGridDBColumn *ViewCSDomainsidprotection;
	TcxGridDBColumn *ViewCSDomainsdonotrenew;
	TDataSource *DSServers;
	TDataSource *DSPackages;
	TLabel *Label1;
	TcxDBDateEdit *cxDBDateEdit1;
	TcxDBDateEdit *cxDBDateEdit2;
	TLabel *Label2;
	TImageList *ImageList1;
	TJvPanel *JvPanel2;
	TJvToolBar *JvToolBar2;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolButton1;
	TJvPanel *JvPanel3;
	TcxButton *cxButton1;
	TcxTextEdit *editSearch;
	TJvPanel *JvPanel4;
	TJvPanel *JvPanel5;
	TJvToolBar *JvToolBar3;
	TToolButton *ToolButton3;
	TToolButton *ToolButton5;
	TToolButton *ToolButton7;
	TToolButton *ToolEditDomains;
	TToolButton *ToolAcceptDomains;
	TToolButton *ToolCancelDomains;
	TToolButton *ToolButton12;
	TToolButton *ToolButton13;
	TLabel *Label3;
	TLabel *Label4;
	TcxDBDateEdit *cxDBDateEdit3;
	TcxDBDateEdit *cxDBDateEdit4;
	TLabel *Label5;
	TcxDBDateEdit *cxDBDateEdit5;
	TTabSheet *TabSheet2;
	TcxGridDBColumn *ViewCSCustomerFullname;
	TUniQuery *QueryPackages;
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
	TUniQuery *QueryClients;
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
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall cxTextEdit1PropertiesChange(TObject *Sender);
	void __fastcall cxButton2Click(TObject *Sender);
	void __fastcall ToolRefreshClick(TObject *Sender);
	void __fastcall ToolEditDomainsClick(TObject *Sender);
	void __fastcall ToolAcceptDomainsClick(TObject *Sender);
	void __fastcall ToolCancelDomainsClick(TObject *Sender);
	void __fastcall QueryClientsCalcFields(TDataSet *DataSet);

private:	// User declarations

public:		// User declarations
	__fastcall TFrmManageCSUsers(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageCSUsers *FrmManageCSUsers;
//---------------------------------------------------------------------------
#endif
