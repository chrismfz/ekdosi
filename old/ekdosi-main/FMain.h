//---------------------------------------------------------------------------

#ifndef FMainH
#define FMainH
#include "cxCheckBox.hpp"
#include "cxClasses.hpp"
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
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExComCtrls.hpp"
#include "JvExControls.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLabel.hpp"
#include "JvMenus.hpp"
#include "JvPanel.hpp"
#include "JvSplit.hpp"
#include "JvStatusBar.hpp"
#include <ActnList.hpp>
#include <Classes.hpp>
#include <ComCtrls.hpp>
#include <Controls.hpp>
#include <DB.hpp>
#include <ExtCtrls.hpp>
#include <Menus.hpp>
#include <StdCtrls.hpp>
#include "cxNavigator.hpp"

#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBDatabase.hpp>
#include <System.Actions.hpp>
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"
#include "dxSkinBlack.hpp"
#include "dxSkinBlue.hpp"
#include "dxSkinBlueprint.hpp"
#include "dxSkinCaramel.hpp"
#include "dxSkinCoffee.hpp"
#include "dxSkinDarkRoom.hpp"
#include "dxSkinDarkSide.hpp"
#include "dxSkinDevExpressDarkStyle.hpp"
#include "dxSkinDevExpressStyle.hpp"
#include "dxSkinFoggy.hpp"
#include "dxSkinGlassOceans.hpp"
#include "dxSkinHighContrast.hpp"
#include "dxSkiniMaginary.hpp"
#include "dxSkinLilian.hpp"
#include "dxSkinLiquidSky.hpp"
#include "dxSkinLondonLiquidSky.hpp"
#include "dxSkinMcSkin.hpp"
#include "dxSkinMetropolis.hpp"
#include "dxSkinMetropolisDark.hpp"
#include "dxSkinMoneyTwins.hpp"
#include "dxSkinOffice2007Black.hpp"
#include "dxSkinOffice2007Blue.hpp"
#include "dxSkinOffice2007Green.hpp"
#include "dxSkinOffice2007Pink.hpp"
#include "dxSkinOffice2007Silver.hpp"
#include "dxSkinOffice2010Black.hpp"
#include "dxSkinOffice2010Blue.hpp"
#include "dxSkinOffice2010Silver.hpp"
#include "dxSkinOffice2013DarkGray.hpp"
#include "dxSkinOffice2013LightGray.hpp"
#include "dxSkinOffice2013White.hpp"
#include "dxSkinOffice2016Colorful.hpp"
#include "dxSkinOffice2016Dark.hpp"
#include "dxSkinPumpkin.hpp"
#include "dxSkinsCore.hpp"
#include "dxSkinsDefaultPainters.hpp"
#include "dxSkinSeven.hpp"
#include "dxSkinSevenClassic.hpp"
#include "dxSkinSharp.hpp"
#include "dxSkinSharpPlus.hpp"
#include "dxSkinSilver.hpp"
#include "dxSkinSpringTime.hpp"
#include "dxSkinStardust.hpp"
#include "dxSkinSummer2008.hpp"
#include "dxSkinTheAsphaltWorld.hpp"
#include "dxSkinTheBezier.hpp"
#include "dxSkinValentine.hpp"
#include "dxSkinVisualStudio2013Blue.hpp"
#include "dxSkinVisualStudio2013Dark.hpp"
#include "dxSkinVisualStudio2013Light.hpp"
#include "dxSkinVS2010.hpp"
#include "dxSkinWhiteprint.hpp"
#include "dxSkinXmas2008Blue.hpp"
#include "DBAccess.hpp"
#include "Uni.hpp"

//---------------------------------------------------------------------------

//---------------------------------------------------------------------------
class TFrmMain : public TForm
{
__published:	// IDE-managed Components
	TJvMainMenu *MainMenu;
	TMenuItem *N1;
	TMenuItem *N6;
	TMenuItem *N7;
	TMenuItem *N8;
	TMenuItem *N9;
	TMenuItem *N2;
	TMenuItem *mnuNewCustomer;
	TMenuItem *mnuShowCustomers;
	TMenuItem *N3;
	TMenuItem *N12;
	TMenuItem *MCalendar;
	TMenuItem *N10;
	TMenuItem *MNewInvoice;
	TMenuItem *WindowMenu;
	TMenuItem *Window1;
	TMenuItem *N5;
	TIBDatabase *database;
	TJvStatusBar *StatusBar;
	TTimer *Timer1;
	TIBTransaction *IBTransaction1;
	TMenuItem *N13;
	TMenuItem *N21;
	TMenuItem *N22;
	TMenuItem *N23;
	TMenuItem *N14;
	TMenuItem *N15;
	TMenuItem *N16;
	TMenuItem *N17;
	TMenuItem *N18;
	TMenuItem *menuReportDesign;
	TMenuItem *N20;
	TMenuItem *N24;
	TMenuItem *N25;
	TMenuItem *N26;
	TMenuItem *N27;
	TMenuItem *N29;
	TMenuItem *N30;
	TMenuItem *N31;
	TMenuItem *N28;
	TMenuItem *CSCart1;
	TMenuItem *NDash;
	TActionList *ActionList1;
	TAction *Action1;
	TMenuItem *N4;
	TMenuItem *N34;
	TMenuItem *N11;
	TJvPanel *PanelCustOrder;
	TcxGrid *GridMilestones;
	TcxGridDBTableView *ViewCustOrder;
	TcxGridLevel *Level1;
	TIBDataSet *DatasetCustomerOrder;
	TDataSource *DSCustomerOrder;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TMenuItem *N19;
	TIBStringField *DatasetCustomerOrderNAME;
	TIBStringField *DatasetCustomerOrderCONCATENATION;
	TcxGridDBColumn *ViewCustOrderCASE;
	TcxGridDBColumn *ViewCustOrderNAME;
	TcxGridDBColumn *ViewCustOrderCONCATENATION;
	TIntegerField *DatasetCustomerOrderCASE;
	TMenuItem *MenuShowCustOrder;
	TJvPanel *PanelSearch;
	TJvLabel *lblCaptionName;
	TJvLabel *lblName;
	TJvLabel *lblCaptionAddress;
	TJvLabel *lblAddress;
	TJvLabel *lblCaptionPhone;
	TJvLabel *lblTelephone;
	TcxGridDBColumn *ViewCustOrderPHONE;
	TIBStringField *DatasetCustomerOrderPHONE;
	TJvDotNetEdit *editSearch;
	TJvLabel *LabelEx;
	TMenuItem *N32;
	TJvPanel *PanelComponents;
	TJvxSplitter *Splitter1;
	TIntegerField *DatasetCustomerOrderCUST_ID;
	TMenuItem *N33;
	TMenuItem *CSUsers1;
	TMenuItem *CSInvoices1;
	TMenuItem *mail;
	TMenuItem *MNewInvoice2;
	TMenuItem *myDATA1;
	TMenuItem *N35;
	TMenuItem *menuRemainingMyData;
	TMenuItem *N36;
	TMenuItem *AboutOptimum1;
	void __fastcall mnuNewCustomerClick(TObject *Sender);
	void __fastcall FormClose(TObject *Sender, TCloseAction &Action);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall FormCreate(TObject *Sender);
	void __fastcall databaseAfterConnect(TObject *Sender);
	void __fastcall FormResize(TObject *Sender);
	void __fastcall Timer1Timer(TObject *Sender);
	void __fastcall mnuShowCustomersClick(TObject *Sender);
	void __fastcall N23Click(TObject *Sender);
	void __fastcall N21Click(TObject *Sender);
	void __fastcall N12Click(TObject *Sender);
	void __fastcall N22Click(TObject *Sender);
	void __fastcall MParametersClick(TObject *Sender);
	void __fastcall MCalendarClick(TObject *Sender);
	void __fastcall MNewInvoiceClick(TObject *Sender);
	void __fastcall N16Click(TObject *Sender);
	void __fastcall N17Click(TObject *Sender);
	void __fastcall N18Click(TObject *Sender);
	void __fastcall menuReportDesignClick(TObject *Sender);
	void __fastcall N20Click(TObject *Sender);
	void __fastcall N9Click(TObject *Sender);
	void __fastcall N6Click(TObject *Sender);
	void __fastcall N7Click(TObject *Sender);
	void __fastcall databaseAfterDisconnect(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall N27Click(TObject *Sender);
	void __fastcall N25Click(TObject *Sender);
	void __fastcall N30Click(TObject *Sender);
	void __fastcall N31Click(TObject *Sender);
	void __fastcall databaseBeforeConnect(TObject *Sender);
	void __fastcall N28Click(TObject *Sender);
	void __fastcall CSCart1Click(TObject *Sender);
	void __fastcall showReportManager(TObject *Sender);
	void __fastcall N34Click(TObject *Sender);
	void __fastcall N11Click(TObject *Sender);
	void __fastcall MenuShowCustOrderClick(TObject *Sender);
	void __fastcall N19Click(TObject *Sender);
	void __fastcall DatasetCustomerOrderAfterScroll(TDataSet *DataSet);
	void __fastcall DatasetCustomerOrderBeforeOpen(TDataSet *DataSet);
	void __fastcall editSearchChange(TObject *Sender);
	void __fastcall LabelExMouseEnter(TObject *Sender);
	void __fastcall LabelExMouseLeave(TObject *Sender);
	void __fastcall LabelExClick(TObject *Sender);
	void __fastcall ViewCustOrderDblClick(TObject *Sender);
	void __fastcall PanelCustOrderResize(TObject *Sender);
	void __fastcall N33Click(TObject *Sender);
	void __fastcall CSUsers1Click(TObject *Sender);
	void __fastcall CSInvoices1Click(TObject *Sender);
	void __fastcall MNewInvoice2Click(TObject *Sender);
	void __fastcall N35Click(TObject *Sender);
	void __fastcall menuRemainingMyDataClick(TObject *Sender);
	void __fastcall N36Click(TObject *Sender);
	void __fastcall AboutOptimum1Click(TObject *Sender);
private:	// User declarations
	bool LoadingFlag;
	void connectDb();
	TDate runningDate;
	void disableMenus();
	void enableMenus();
	void showCustomerOrder();
	void hideCustomerOrder();
	void __fastcall executeAddInvoice(TObject *Sender);
	void __fastcall executeAddInvCustId(TObject *Sender);
	void __fastcall openReport(TObject *Sender);
	void showMessage(AnsiString _message, AnsiString AppName, UINT btnType);
	TColor primary, secondary, selectedColor;
	TStringList *defaultSQL;
public:		// User declarations
	AnsiString isLocal();
 bool autoInvoiceEnabled();
	void executeConnect();
	void showClock();
	void setDate(TDate date);
	void createChildMenuReport();
	void createChildMenus(); 
	__fastcall TFrmMain(TComponent* Owner);
	TIBDatabase * getDatabase();
	TDate getRunningDate();
	AnsiString findInvType(int custId);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmMain *FrmMain;
//---------------------------------------------------------------------------
#endif
