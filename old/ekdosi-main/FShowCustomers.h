//---------------------------------------------------------------------------

#ifndef FShowCustomersH
#define FShowCustomersH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvDotNetControls.hpp"
#include "JvExComCtrls.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLookOut.hpp"
#include "JvPanel.hpp"
#include "JvRadioButton.hpp"
#include "JvRollOut.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <Mask.hpp>
#include <ToolWin.hpp>
#include "JvLabel.hpp"
#include "JvComCtrls.hpp"
#include "JvAppIniStorage.hpp"
#include "JvAppStorage.hpp"
#include "JvComponentBase.hpp"

#include "CNewSpecialForm.h"
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
#include "cxStyles.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include <System.ImageList.hpp>
#include "cxContainer.hpp"
#include "cxDBEdit.hpp"
#include "cxDBLookupComboBox.hpp"
#include "cxDBLookupEdit.hpp"
#include "cxDropDownEdit.hpp"
#include "cxLookupEdit.hpp"
#include "cxMaskEdit.hpp"
#include "cxMemo.hpp"
#include "cxNavigator.hpp"
#include "cxRadioGroup.hpp"
#include "cxTextEdit.hpp"
#include "cxButtons.hpp"
#include <Vcl.Menus.hpp>
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
#include "cxCheckBox.hpp"

#include <vector>
#include "CEditBox.h"

using namespace std;

//---------------------------------------------------------------------------
class TFrmShowCustomers : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvPanel *PanelTop;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvPanel *PanelSearch;
	TJvExpressButton *btnAdd;
	TImageList *ImageList1;
	TJvPanel *JvPanel1;
	TJvPanel *PanelMain;
	TDataSource *DSCustomers;
	TStatusBar *StatusBar1;
	TLabel *Label1;
	TLabel *Label9;
	TJvDotNetDBEdit *DBEditName;
	TJvRollOut *RollDetail;
	TLabel *Label2;
	TLabel *Label3;
	TLabel *Label4;
	TLabel *Label5;
	TLabel *Label6;
	TLabel *Label7;
	TLabel *Label8;
	TLabel *Label10;
	TLabel *Label11;
	TLabel *Label12;
	TImageList *ImageList2;
	TJvLabel *lblCheck;
	TJvPageControl *PageControl;
	TTabSheet *TabSheet1;
	TTabSheet *TabSheet2;
	TJvPanel *JvPanel2;
	TIBQuery *QueryInvoice;
	TIntegerField *QueryInvoiceINVOICE_ID;
	TIBStringField *QueryInvoiceINVCODE;
	TIntegerField *QueryInvoiceCUST_ID;
	TIBStringField *QueryInvoiceINVTYPE;
	TDateField *QueryInvoiceINVDATE;
	TSmallintField *QueryInvoicePRINTED;
	TDateField *QueryInvoiceDELIVERYDATE;
	TIntegerField *QueryInvoiceDISTRAIM_ID;
	TIntegerField *QueryInvoiceDELMETHOD_ID;
	TIntegerField *QueryInvoicePAYMETH_ID;
	TIBBCDField *QueryInvoiceDISCOUNT;
	TIBBCDField *QueryInvoicePRICE;
	TIBBCDField *QueryInvoicePRICEWVAT;
	TDataSource *DSInvoices;
	TIntegerField *QueryInvoiceCONV_INVOICE_ID;
	TTimeField *QueryInvoiceINVTIME;
	TIBStringField *QueryInvoicePAYMETHOD;
	TTabSheet *TabSheet3;
	TJvPanel *JvPanel3;
	TDataSource *DSPayments;
	TJvDotNetButton *JvDotNetButton2;
	TJvPanel *JvPanel4;
	TJvToolBar *JvToolBar2;
	TToolButton *ToolsPriorPayments;
	TToolButton *ToolNextPayments;
	TToolButton *ToolButton5;
	TToolButton *ToolAddPayments;
	TToolButton *TollDeletePayments;
	TToolButton *ToolButton9;
	TToolButton *ToolRefreshPayments;
	TIBDataSet *DatasetPayment;
	TIntegerField *DatasetPaymentPAYMENT_ID;
	TIntegerField *DatasetPaymentCUST_ID;
	TDateField *DatasetPaymentPAY_DATE;
	TIBBCDField *DatasetPaymentVALUE;
	TMemoField *DatasetPaymentNOTES;
	TLabel *Label13;
	TJvDotNetButton *JvDotNetButton3;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TcxGrid *GridCustomers;
	TcxGridDBTableView *ViewCustomers;
	TcxGridLevel *GridCustomersLevel1;
	TcxGridDBColumn *ViewCustomersCUST_ID;
	TcxGridDBColumn *ViewCustomersNAME;
	TcxGridDBColumn *ViewCustomersCITY;
	TcxGridDBColumn *ViewCustomersOCCUPATION;
	TcxGridDBColumn *ViewCustomersBALANCE;
	TcxGridDBColumn *ViewCustomersAFM;
	TcxDBLookupComboBox *cxDBLookupComboBox2;
	TIBQuery *QueryOccupation;
	TIBStringField *QueryOccupationOCCUPATION;
	TDataSource *DSOcupation;
	TcxDBLookupComboBox *cxDBLookupComboBox3;
	TIBQuery *QueryTaxOffice;
	TIBStringField *QueryTaxOfficeTAXOFFICE;
	TDataSource *DSTaxOffice;
	TIBQuery *QueryCity;
	TIBStringField *QueryCityCITY;
	TDataSource *DSCity;
	TcxDBLookupComboBox *cxDBLookupComboBox4;
	TLabel *Label14;
	TcxDBLookupComboBox *cxDBLookupComboBox1;
	TIBQuery *QueryCountry;
	TIBStringField *QueryCountryCOUNTRY;
	TDataSource *DSCountry;
	TIBStringField *QueryInvoiceADDRESS1;
	TIBStringField *QueryInvoiceADDRESS2;
	TIBStringField *QueryInvoiceCITY;
	TIBStringField *QueryInvoicePOSTCODE;
	TIBStringField *QueryInvoiceCOUNTRY;
	TIBStringField *QueryInvoiceNOTES;
	TIntegerField *QueryInvoiceCODE;
	TcxDBTextEdit *cxDBTextEdit2;
	TcxGrid *GridCustInvoices;
	TcxGridDBTableView *ViewCustInvoices;
	TcxGridLevel *GridLevelCustInvoices;
	TcxGridDBColumn *ViewCustInvoicesINVOICE_ID;
	TcxGridDBColumn *ViewCustInvoicesINVCODE;
	TcxGridDBColumn *ViewCustInvoicesINVDATE;
	TcxGridDBColumn *ViewCustInvoicesPRICEWVAT;
	TcxGridDBColumn *ViewCustInvoicesPAYMETHOD;
	TLabel *Label15;
	TcxDBLookupComboBox *cxDBLookupComboBox5;
	TIBQuery *QueryPaymentMethod;
	TIntegerField *QueryPaymentMethodMETHOD_ID;
	TIBStringField *QueryPaymentMethodDESCRIPTION;
	TIntegerField *QueryPaymentMethodDUE_DAYS;
	TDataSource *DSPaymentMethod;
	TIBDataSet *DatasetCustomers;
	TIntegerField *DatasetCustomersCUST_ID;
	TIntegerField *DatasetCustomersALT_CUSTID;
	TIBStringField *DatasetCustomersAFM;
	TIBStringField *DatasetCustomersNAME;
	TIBStringField *DatasetCustomersADDRESS1;
	TIBStringField *DatasetCustomersADDRESS2;
	TIBStringField *DatasetCustomersCITY;
	TIBStringField *DatasetCustomersPOSTCODE;
	TIBStringField *DatasetCustomersOCCUPATION;
	TIBStringField *DatasetCustomersTAXOFFICE;
	TWideMemoField *DatasetCustomersDETAILS;
	TIBBCDField *DatasetCustomersDISCOUNT;
	TIBStringField *DatasetCustomersEMAIL;
	TIntegerField *DatasetCustomersORDER;
	TIBStringField *DatasetCustomersCOUNTRY;
	TIntegerField *DatasetCustomersPAYMETH_ID;
	TIBBCDField *DatasetCustomersBALANCE;
	TcxDBMemo *cxDBMemo1;
	TcxGridDBTableView *ViewMonthGroup;
	TIBQuery *QueryMonthGroup;
	TDataSource *DSMonthGroup;
	TIBStringField *QueryMonthGroupMONTH_DATE;
	TDateField *QueryMonthGroupFIRSTDATE;
	TcxGridDBColumn *ViewMonthGroupMONTH_DATE;
	TcxGridDBColumn *ViewMonthGroupSUM;
	TcxRadioButton *radioMonthlyGroup;
	TcxRadioButton *cxRadioButton2;
	TcxGridDBColumn *ViewMonthGroupColumn1;
	TIBBCDField *QueryMonthGroupSUM_0DUE;
	TIBBCDField *QueryMonthGroupSUM_DUE;
	TcxGrid *cxGrid1;
	TcxGridDBTableView *cxGridDBTableView1;
	TcxGridLevel *cxGridLevel1;
	TcxGridDBColumn *cxGridDBTableView1PAYMENT_ID;
	TcxGridDBColumn *cxGridDBTableView1PAY_DATE;
	TcxGridDBColumn *cxGridDBTableView1VALUE;
	TcxDBTextEdit *cxDBTextEdit1;
	TLabel *Label16;
	TIBStringField *DatasetCustomersVAT_VIES;
	TcxDBTextEdit *cxDBTextEdit3;
	TcxDBTextEdit *editPercent;
	TcxDBTextEdit *cxDBTextEdit5;
	TLabel *Label17;
	TIBStringField *DatasetCustomersSECONDARY_EMAIL;
	TJvDotNetButton *JvDotNetButton5;
	TIBStringField *DatasetCustomersPHONE1;
	TIBStringField *DatasetCustomersPHONE2;
	TIBStringField *DatasetCustomersFAX;
	TcxDBTextEdit *cxDBTextEdit4;
	TcxDBTextEdit *cxDBTextEdit6;
	TcxDBTextEdit *cxDBTextEdit7;
	TcxDBTextEdit *editVatNo;
	TcxDBTextEdit *cxDBTextEdit8;
	TcxDBTextEdit *cxDBTextEdit9;
	TcxButton *cxButton1;
	TcxDBCheckBox *cxDBCheckBox1;
	TIBStringField *DatasetCustomersTYPE;
	TIntegerField *DatasetCustomersWITHHOLD_TAX;
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall JvDotNetDBEdit4KeyPress(TObject *Sender, char &Key);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolPAddClick(TObject *Sender);
	void __fastcall editPercentExit(TObject *Sender);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall editVatNoExit(TObject *Sender);
	void __fastcall DatasetCustomerAfterScroll(TDataSet *DataSet);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall ToolsPriorPaymentsClick(TObject *Sender);
	void __fastcall ToolNextPaymentsClick(TObject *Sender);
	void __fastcall ToolRefreshPaymentsClick(TObject *Sender);
	void __fastcall TollDeletePaymentsClick(TObject *Sender);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall DatasetCustomerAfterInsert(TDataSet *DataSet);
	void __fastcall JvDotNetButton3Click(TObject *Sender);
	void __fastcall btnAddClick(TObject *Sender);
	void __fastcall ViewCustInvoicesCellDblClick(TcxCustomGridTableView *Sender, TcxGridTableDataCellViewInfo *ACellViewInfo,
          TMouseButton AButton, TShiftState AShift,
          bool &AHandled);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall DatasetCustomersAfterOpen(TDataSet *DataSet);
	void __fastcall radioMonthlyGroupClick(TObject *Sender);
	void __fastcall QueryMonthGroupBeforeOpen(TDataSet *DataSet);
	void __fastcall PageControlChange(TObject *Sender);
	void __fastcall JvDotNetButton5Click(TObject *Sender);
	void __fastcall cxDBTextEdit8PropertiesChange(TObject *Sender);
	void __fastcall cxButton1Click(TObject *Sender);


private:	// User declarations
	int selCol, selRow;
	vector<EditBox *>  editBoxes;
	vector<EditBox *> garbage;
	TStringList *defaultSQL;
	void __fastcall btnMinusClick(TObject *Sender);
	void __fastcall editSearchChange(TObject *Sender);
	bool checkVatExists(AnsiString _vatNumber);
	AnsiString getInvoiceFilename(AnsiString _invTypeId);
public:		// User declarations
	__fastcall TFrmShowCustomers(TComponent* Owner);
	void locateCust(int _id);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowCustomers *FrmShowCustomers;
//---------------------------------------------------------------------------
#endif
